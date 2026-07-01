import os

from flask import Flask, jsonify, request
from flask_cors import CORS
import mysql.connector

from face_utils import best_face_match, encoding_to_json, extract_face_encodings, json_to_encoding

app = Flask(__name__)
CORS(app)

FACE_MATCH_THRESHOLD = float(os.getenv("FACE_MATCH_THRESHOLD", "0.55"))


def db_connection():
    host = os.getenv("DB_HOST", "sql205.infinityfree.com")
    user = os.getenv("DB_USER", "if0_42299174")
    password = os.getenv("DB_PASSWORD", "JGcXHAoq1ANypi")
    database = os.getenv("DB_NAME", "if0_42299174_Face_Attendance")
    port = int(os.getenv("DB_PORT", "3306"))

    return mysql.connector.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=database,
    )


import time

KNOWN_ENCODINGS = None
LAST_CACHE_TIME = 0
CACHE_TTL = 60
CACHE_DIRTY = True

def load_cache(force=False):
    global KNOWN_ENCODINGS, LAST_CACHE_TIME, CACHE_DIRTY
    now = time.time()
    if force or KNOWN_ENCODINGS is None or CACHE_DIRTY or (now - LAST_CACHE_TIME) > CACHE_TTL:
        try:
            connection = db_connection()
            cursor = connection.cursor(dictionary=True)
            cursor.execute(
                """
                SELECT fe.student_id, fe.encoding, s.name, s.roll_no
                FROM face_encodings fe
                INNER JOIN students s ON s.id = fe.student_id
                """
            )
            rows = cursor.fetchall()
            cursor.close()
            connection.close()

            parsed_rows = []
            for row in rows:
                try:
                    parsed_rows.append({
                        "student_id": row["student_id"],
                        "student_name": row["name"],
                        "name": row["name"],
                        "roll_no": row.get("roll_no", ""),
                        "encoding": json_to_encoding(row["encoding"])
                    })
                except Exception:
                    pass

            KNOWN_ENCODINGS = parsed_rows
            LAST_CACHE_TIME = now
            CACHE_DIRTY = False
        except Exception as e:
            if KNOWN_ENCODINGS is not None:
                print("Cache reload failed, using stale cache:", e)
            else:
                raise e


@app.get("/")
def index():
    return jsonify({
        "message": "FaceTrack Attendance API is running",
        "endpoints": ["/health", "/register", "/recognize", "/recognize_batch", "/reload_cache"]
    })


@app.get("/health")
def health():
    return jsonify({"status": "ok"})


@app.post("/reload_cache")
def reload_cache():
    global CACHE_DIRTY
    CACHE_DIRTY = True
    try:
        load_cache(force=True)
        count = len(KNOWN_ENCODINGS) if KNOWN_ENCODINGS else 0
        return jsonify({"status": "ok", "message": "Cache reloaded successfully", "count": count})
    except Exception as exc:
        return jsonify({"error": "Failed to reload cache", "details": str(exc)}), 500


@app.post("/register")
def register_face():
    student_id = request.form.get("student_id", type=int)
    files = list(request.files.values())

    if not student_id:
        return jsonify({"error": "student_id is required."}), 400

    if len(files) < 1:
        return jsonify({"error": "At least one image is required for registration."}), 400

    saved_encodings = []

    for file in files:
        image_bytes = file.read()
        encodings = extract_face_encodings(image_bytes)

        if not encodings:
            return jsonify({"error": f"No face detected in file {file.filename or 'upload'}."}), 422

        if len(encodings) > 1:
            return jsonify({"error": f"Multiple faces detected in file {file.filename or 'upload'}."}), 422

        saved_encodings.extend(encodings)

    try:
        connection = db_connection()
    except Exception as exc:
        return jsonify({"error": "Database connection failed.", "details": str(exc)}), 500

    cursor = connection.cursor()

    try:
        for encoding in saved_encodings:
            cursor.execute(
                "INSERT INTO face_encodings (student_id, encoding) VALUES (%s, %s)",
                (student_id, encoding_to_json(encoding)),
            )
        connection.commit()
    finally:
        cursor.close()
        connection.close()

    global CACHE_DIRTY
    CACHE_DIRTY = True

    return jsonify({
        "student_id": student_id,
        "encodings_saved": len(saved_encodings),
    })


@app.post("/recognize")
def recognize_face():
    file = request.files.get("image")

    if file is None:
        return jsonify({"error": "image is required."}), 400

    image_bytes = file.read()
    candidate_encodings = extract_face_encodings(image_bytes)

    if not candidate_encodings:
        return jsonify({
            "matched": False,
            "message": "No face detected in the submitted image.",
        }), 422

    if len(candidate_encodings) > 1:
        return jsonify({
            "matched": False,
            "message": "Multiple faces detected. Please capture one student at a time.",
        }), 422

    candidate = candidate_encodings[0]

    try:
        load_cache()
    except Exception as exc:
        return jsonify({"error": "Database cache reload failed.", "details": str(exc)}), 500

    if not KNOWN_ENCODINGS:
        return jsonify({
            "matched": False,
            "message": "No face encodings are registered yet.",
        }), 404

    known_encodings = [row["encoding"] for row in KNOWN_ENCODINGS]
    best_index, confidence = best_face_match(known_encodings, candidate)

    if best_index is None:
        return jsonify({"matched": False, "message": "No matching face found."})

    matched_row = KNOWN_ENCODINGS[best_index]

    if confidence < FACE_MATCH_THRESHOLD:
        return jsonify({
            "matched": False,
            "message": "Face found but confidence is below the accepted threshold.",
            "confidence": confidence,
        })

    return jsonify({
        "matched": True,
        "student_id": matched_row["student_id"],
        "student_name": matched_row["name"],
        "confidence": confidence,
    })


@app.post("/recognize_batch")
def recognize_batch():
    """Detect ALL faces in one image and return every match above threshold."""
    file = request.files.get("image")

    if file is None:
        return jsonify({"error": "image is required."}), 400

    image_bytes = file.read()
    candidate_encodings = extract_face_encodings(image_bytes)

    if not candidate_encodings:
        return jsonify({
            "matches": [],
            "faces_detected": 0,
            "message": "No faces detected in the submitted image.",
        }), 422

    try:
        load_cache()
    except Exception as exc:
        return jsonify({"error": "Database cache reload failed.", "details": str(exc)}), 500

    if not KNOWN_ENCODINGS:
        return jsonify({
            "matches": [],
            "faces_detected": len(candidate_encodings),
            "message": "No face encodings are registered yet.",
        }), 404

    known_encodings = [row["encoding"] for row in KNOWN_ENCODINGS]
    matches = []
    matched_student_ids = set()

    for candidate in candidate_encodings:
        best_index, confidence = best_face_match(known_encodings, candidate)

        if best_index is None or confidence < FACE_MATCH_THRESHOLD:
            continue

        matched_row = KNOWN_ENCODINGS[best_index]
        student_id = matched_row["student_id"]

        # Skip if this student was already matched by another face crop
        if student_id in matched_student_ids:
            continue

        matched_student_ids.add(student_id)
        matches.append({
            "student_id": student_id,
            "student_name": matched_row["student_name"],
            "roll_no": matched_row.get("roll_no", ""),
            "confidence": confidence,
        })

    return jsonify({
        "matches": matches,
        "faces_detected": len(candidate_encodings),
        "message": f"{len(matches)} student(s) recognized out of {len(candidate_encodings)} face(s) detected.",
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=7860, debug=False)
