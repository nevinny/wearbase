// Слой «кадр + плашка-караоке»: плашка = фраза голоса целиком (читается без звука с первого кадра),
// текущее слово подсвечено по таймингам whisper. Одна плашка на кадр — второй блок с тем же текстом не рисуем.
import React from 'react';
import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, spring, interpolate, continueRender, delayRender} from 'remotion';

const font = new FontFace('Wardrobe', `url(${staticFile('NotoSans.ttf')})`, {weight: '100 900'});
const fontHandle = delayRender('font');
font.load().then(f => { document.fonts.add(f); continueRender(fontHandle); });

export type Word = {text: string; start: number; end: number};
export type Chunk = {from: number; words: Word[]};
export type Beat = {
  from: number; to: number; kind: 'photo' | 'cta' | 'loop';
  image: string; zoom?: [number, number];
  push?: {at: number; scale: number; cx: number; cy: number};
  mark?: {at: number; x: number; y: number; w: number; h: number};
  chunks: Chunk[];
  button?: {text: string; at: number};
  band?: number;
};
export type ReelProps = {total: number; band: number; beats: Beat[]};

const clamp = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;
const YELLOW = '#FFD84D';

const Plate: React.FC<{chunk: Chunk; t: number; frame: number; fps: number; center: number; animateIn: boolean; big?: boolean}> = ({chunk, t, frame, fps, center, animateIn, big}) => {
  const s = animateIn ? spring({frame: frame - Math.round(chunk.from * fps), fps, config: {damping: 14, stiffness: 180}}) : 1;
  const words = chunk.words;
  return (
    <div style={{position: 'absolute', left: 50, right: 50, top: center, transform: `translateY(-50%) scale(${0.92 + 0.08 * s})`, opacity: Math.min(1, s * 1.6), display: 'flex', justifyContent: 'center'}}>
      <div style={{background: 'rgba(20,20,24,.72)', borderRadius: 28, padding: '22px 34px 26px', textAlign: 'center', color: '#fff', fontSize: big ? 88 : 80, lineHeight: 1.18, fontWeight: 800, maxWidth: 980}}>
        {words.map((w, i) => {
          // Подсветка держится до начала следующего слова (без мигания в паузах до 0.35 с).
          const next = words[i + 1]?.start ?? w.end + 0.35;
          const active = t >= w.start && t < Math.min(next, w.end + 0.35);
          const k = active ? interpolate(t, [w.start, w.start + 0.08], [0, 1], clamp) : 0;
          return (
            <span key={i} style={{display: 'inline-block', margin: '0 .17em', color: active ? YELLOW : '#fff', transform: `scale(${1 + 0.07 * k})`, transformOrigin: '50% 70%'}}>{w.text}</span>
          );
        })}
      </div>
    </div>
  );
};

const BeatView: React.FC<{beat: Beat; t: number; frame: number; fps: number; band: number; first: boolean}> = ({beat, t, frame, fps, band, first}) => {
  const [z0, z1] = beat.zoom ?? [1, 1.05];
  let scale = interpolate(t, [beat.from, beat.to], [z0, z1], clamp);
  let origin = '50% 50%';
  if (beat.push) {
    const p = interpolate(t, [beat.push.at, beat.push.at + 0.6], [0, 1], {...clamp, easing: x => 1 - Math.pow(1 - x, 3)});
    scale = scale * (1 + (beat.push.scale - 1) * p);
    origin = `${beat.push.cx}px ${beat.push.cy}px`;
  }
  const chunkIndex = beat.chunks.reduce((acc, c, i) => (t >= c.from ? i : acc), 0);
  const chunk = beat.chunks[chunkIndex];
  const cta = beat.kind === 'cta';
  const markOpacity = beat.mark ? interpolate(t, [beat.mark.at, beat.mark.at + 0.25], [0, 1], clamp) : 0;
  return (
    <AbsoluteFill>
      <AbsoluteFill style={{transform: `scale(${scale})`, transformOrigin: origin}}>
        <Img src={staticFile(beat.image)} style={{width: 1080, height: 1920, objectFit: 'cover', filter: cta ? 'blur(26px) brightness(.55)' : undefined, transform: cta ? 'scale(1.1)' : undefined}} />
        {beat.mark && (
          <div style={{position: 'absolute', left: beat.mark.x, top: beat.mark.y, width: beat.mark.w, height: beat.mark.h, borderRadius: 14, border: `6px solid ${YELLOW}`, background: 'rgba(255,216,77,.22)', opacity: markOpacity}} />
        )}
      </AbsoluteFill>
      {chunk && (
        <Plate chunk={chunk} t={t} frame={frame} fps={fps} center={beat.band ?? band}
          animateIn={!(first && chunkIndex === 0) && beat.kind !== 'loop'} big={cta || beat.kind === 'loop' || (first && chunkIndex === 0)} />
      )}
      {beat.button && t >= beat.button.at - 0.6 && (() => {
        const s = spring({frame: frame - Math.round((beat.button!.at - 0.6) * fps), fps, config: {damping: 11, stiffness: 160}});
        const pulse = 1 + 0.04 * Math.max(0, Math.sin((t - beat.button!.at) * Math.PI * 2 / 0.9));
        return (
          <div style={{position: 'absolute', left: 0, right: 0, top: (beat.band ?? band) + 250, display: 'flex', justifyContent: 'center'}}>
            <div style={{background: '#5B4BE8', color: '#fff', fontSize: 72, fontWeight: 800, borderRadius: 26, padding: '22px 46px', transform: `scale(${s * pulse})`, boxShadow: '0 14px 40px rgba(0,0,0,.35)'}}>{beat.button!.text}</div>
          </div>
        );
      })()}
    </AbsoluteFill>
  );
};

export const VoicedReel: React.FC<ReelProps> = ({beats, band}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const t = frame / fps;
  const found = beats.findIndex(b => t >= b.from && t < b.to);
  const i = found === -1 ? beats.length - 1 : found;
  const beat = beats[i] ?? beats[beats.length - 1];
  return (
    <AbsoluteFill style={{background: '#000', fontFamily: 'Wardrobe'}}>
      {beat && <BeatView beat={beat} t={t} frame={frame} fps={fps} band={band} first={i === 0} />}
    </AbsoluteFill>
  );
};
