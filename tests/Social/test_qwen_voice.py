from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/wardrobe-reels"))
from qwen_voice import align_scenes, tokens, validate_request


class QwenAlignmentTest(unittest.TestCase):
    texts = ["Шкаф полный.", "Нечего надеть?", "Добавь свои вещи.", "Собирай новые образы.", "Начни сегодня."]

    def words(self, texts=None):
        words = []
        position = .2
        for text in self.texts if texts is None else texts:
            for word in tokens(text):
                words.append({"word": word, "start": position, "end": position + .3})
                position += .4
            position += .5
        return words, position

    def test_continuous_timeline_uses_actual_word_times(self):
        words, duration = self.words()
        scenes, similarity = align_scenes(self.texts, words, duration)
        self.assertEqual(1, similarity)
        self.assertEqual(0, scenes[0]["start"])
        self.assertEqual(duration, scenes[-1]["end"])
        self.assertAlmostEqual((words[1]["end"] + words[2]["start"]) / 2, scenes[0]["end"])
        for previous, current in zip(scenes, scenes[1:]):
            self.assertEqual(previous["end"], current["start"])

    def test_missing_scene_is_rejected_even_when_overall_text_is_similar(self):
        words, duration = self.words(self.texts[:2] + self.texts[3:])
        with self.assertRaisesRegex(ValueError, "Scene 3"):
            align_scenes(self.texts, words, duration)

    def test_unrelated_speech_is_rejected(self):
        words, duration = self.words(["Сегодня хорошая погода за окном."])
        with self.assertRaisesRegex(ValueError, "differs"):
            align_scenes(self.texts, words, duration)

    def test_non_monotonic_word_times_are_rejected(self):
        words, duration = self.words()
        words[1]["start"], words[1]["end"] = 0, .1
        with self.assertRaisesRegex(ValueError, "out of order"):
            align_scenes(self.texts, words, duration)

    def test_yo_and_punctuation_are_normalized(self):
        self.assertEqual(tokens("Всё ещё: вещи!"), tokens("все еще вещи"))

    def test_empty_or_oversized_requests_are_rejected(self):
        for request in [{}, {"version": 1, "texts": [""] * 5}, {"version": 1, "texts": ["а" * 301] * 5}, []]:
            with self.assertRaises(ValueError):
                validate_request(request)
        self.assertEqual(self.texts, validate_request({"version": 1, "texts": self.texts}))


if __name__ == "__main__":
    unittest.main()
