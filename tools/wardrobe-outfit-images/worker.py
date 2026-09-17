#!/usr/bin/env python3
"""Run on the LLM server from cron. Requires only Python's standard library."""

import base64
import hashlib
import hmac
import json
import os
import time
import urllib.parse
import urllib.request
import uuid


PROD = os.environ['WEARBASE_URL'].rstrip('/')
COMFY = os.environ.get('COMFYUI_URL', 'http://127.0.0.1:8188').rstrip('/')
TOKEN = os.environ['AGENT_API_TOKEN']
SECRET = os.environ['AGENT_API_SECRET'].encode()


def request(url, body=None, headers=None, timeout=30):
    with urllib.request.urlopen(urllib.request.Request(url, data=body, headers=headers or {}), timeout=timeout) as response:
        return response.read()


def upload(image, photo_url):
    boundary = uuid.uuid4().hex
    extension = os.path.splitext(urllib.parse.urlparse(photo_url).path)[1].lower()
    mime = {'.png': 'image/png', '.webp': 'image/webp'}.get(extension, 'image/jpeg')
    extension = extension if extension in ('.jpg', '.jpeg', '.png', '.webp') else '.jpg'
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="item{extension}"\r\n'
            f'Content-Type: {mime}\r\n\r\n').encode() + image + f'\r\n--{boundary}--\r\n'.encode()
    result = request(COMFY + '/upload/image', body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
    return json.loads(result)['name']


def cutout(image, photo_url):
    name = upload(image, photo_url)
    workflow = {
        '1': {'class_type': 'LoadImage', 'inputs': {'image': name}},
        '2': {'class_type': 'BiRefNetRMBG', 'inputs': {
            'image': ['1', 0], 'model': 'BiRefNet-general', 'background': 'Alpha',
            'sensitivity': 1.0, 'mask_blur': 1, 'mask_offset': 0,
            'invert_output': False, 'refine_foreground': False,
        }},
        '3': {'class_type': 'SaveImage', 'inputs': {
            'images': ['2', 0], 'filename_prefix': 'wearbase-outfit',
        }},
    }
    payload = json.dumps({'prompt': workflow}).encode()
    queued = json.loads(request(COMFY + '/prompt', payload, {'Content-Type': 'application/json'}))
    prompt_id = queued['prompt_id']
    for _ in range(120):
        history = json.loads(request(COMFY + '/history/' + prompt_id))
        job = history.get(prompt_id, {})
        output = job.get('outputs', {}).get('3', {}).get('images', [])
        if output:
            image = output[0]
            query = urllib.parse.urlencode({
                'filename': image['filename'], 'subfolder': image.get('subfolder', ''),
                'type': image.get('type', 'output'),
            })
            return request(COMFY + '/view?' + query)
        if job.get('status', {}).get('completed'):
            raise RuntimeError('ComfyUI completed without an image')
        time.sleep(1)
    raise TimeoutError('ComfyUI did not finish within 120 seconds')


def main():
    after = 0
    while True:
        query = urllib.parse.urlencode({'after': after})
        page = json.loads(request(PROD + '/api/v1/wardrobe/outfit-images?' + query,
                                  headers={'X-Agent-Token': TOKEN}))
        for item in page['items']:
            try:
                source = request(item['photo_url'])
                if hashlib.sha256(source).hexdigest() != item['source_hash']:
                    raise ValueError('source changed during download')
                image = cutout(source, item['photo_url'])
                payload = json.dumps({'source_hash': item['source_hash'],
                                      'image_base64': base64.b64encode(image).decode()}).encode()
                signature = hmac.new(SECRET, payload, hashlib.sha256).hexdigest()
                request(PROD + '/api/v1/wardrobe/outfit-images/' + str(item['id']), payload, {
                    'Content-Type': 'application/json', 'X-Agent-Token': TOKEN,
                    'X-Signature': signature,
                }, timeout=60)
                print(f"prepared item {item['id']}")
            except Exception as error:
                print(f"item {item['id']}: {error}", flush=True)
        if page['next_after'] is None or page['next_after'] <= after:
            break
        after = page['next_after']


if __name__ == '__main__':
    main()
