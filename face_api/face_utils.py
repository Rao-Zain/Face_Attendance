import io
import json
from typing import Iterable, List, Optional, Tuple

import face_recognition
import numpy as np


from PIL import Image

def extract_face_encodings(image_bytes: bytes) -> List[np.ndarray]:
    # Load image using PIL
    image_pil = Image.open(io.BytesIO(image_bytes))
    
    if image_pil.mode != "RGB":
        image_pil = image_pil.convert("RGB")
        
    # Resize image if too large to speed up CPU face detection & encoding
    w, h = image_pil.size
    max_dim = 640
    if max(w, h) > max_dim:
        scale = max_dim / max(w, h)
        new_w = int(w * scale)
        new_h = int(h * scale)
        try:
            resample_filter = Image.Resampling.LANCZOS
        except AttributeError:
            resample_filter = Image.ANTIALIAS
        image_pil = image_pil.resize((new_w, new_h), resample=resample_filter)
        
    # Convert PIL Image to numpy array
    image = np.array(image_pil)

    locations = face_recognition.face_locations(image, model="hog")

    if not locations:
        return []

    return face_recognition.face_encodings(image, known_face_locations=locations, num_jitters=1)


def encoding_to_json(encoding: np.ndarray) -> str:
    return json.dumps(encoding.tolist())


def json_to_encoding(payload: str) -> np.ndarray:
    return np.array(json.loads(payload), dtype=np.float64)


def best_face_match(known_encodings: Iterable[np.ndarray], candidate: np.ndarray) -> Tuple[Optional[int], float]:
    known_list = list(known_encodings)

    if not known_list:
        return None, 0.0

    distances = face_recognition.face_distance(known_list, candidate)
    best_index = int(np.argmin(distances))
    best_distance = float(distances[best_index])
    confidence = max(0.0, min(1.0, 1.0 - best_distance))

    return best_index, confidence

