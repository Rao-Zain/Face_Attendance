import io
import json
from typing import Iterable, List

import face_recognition
import numpy as np


import cv2

def extract_face_encodings(image_bytes: bytes) -> List[np.ndarray]:
    image = face_recognition.load_image_file(io.BytesIO(image_bytes))
    
    # Resize image if too large to speed up CPU face detection & encoding
    h, w = image.shape[:2]
    max_dim = 640
    if max(h, w) > max_dim:
        scale = max_dim / max(h, w)
        new_w = int(w * scale)
        new_h = int(h * scale)
        image = cv2.resize(image, (new_w, new_h), interpolation=cv2.INTER_AREA)

    locations = face_recognition.face_locations(image, model="hog")

    if not locations:
        return []

    return face_recognition.face_encodings(image, known_face_locations=locations, num_jitters=1)


def encoding_to_json(encoding: np.ndarray) -> str:
    return json.dumps(encoding.tolist())


def json_to_encoding(payload: str) -> np.ndarray:
    return np.array(json.loads(payload), dtype=np.float64)


def best_face_match(known_encodings: Iterable[np.ndarray], candidate: np.ndarray) -> tuple[int | None, float]:
    known_list = list(known_encodings)

    if not known_list:
        return None, 0.0

    distances = face_recognition.face_distance(known_list, candidate)
    best_index = int(np.argmin(distances))
    best_distance = float(distances[best_index])
    confidence = max(0.0, min(1.0, 1.0 - best_distance))

    return best_index, confidence

