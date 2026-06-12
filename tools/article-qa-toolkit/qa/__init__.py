"""article-qa-toolkit: набор проверок качества текста после генерации LLM.

Каждый подмодуль экспортирует функцию analyze(...) и возвращает словарь вида:
    {
        "name":   "human-readable name",
        "score":  float | None,   # 0..100, None если метрика чисто информационная
        "weight": float,          # вес в итоговой оценке
        "metrics": {...},         # числовые показатели
        "findings": [             # список замечаний
            {"level": "info"|"warn"|"error", "msg": str, "detail": str|None},
            ...
        ],
    }
"""
__version__ = "1.0.0"
