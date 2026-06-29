import os

from flask import Flask, jsonify, request
import mysql.connector

from face_utils import best_face_match, encoding_to_json, extract_face_encodings, json_to_encoding

app = Flask(__name__)

FACE_MATCH_THRESHOLD = float(os.getenv("FACE_MATCH_THRESHOLD", "0.55"))


def db_connection():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST"),
        port=int(os.getenv("DB_PORT", "3306")),
        user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME"),
    )


@app.get("/")
def index():
    return jsonify({
        "message": "FaceTrack Attendance API is running",
        "endpoints": ["/health", "/register", "/recognize", "/recognize_batch"]
    })


@app.get("/health")
def health():
    return jsonify({"status": "ok"})


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

    connection = db_connection()
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

    connection = db_connection()
    cursor = connection.cursor(dictionary=True)

    try:
        cursor.execute(
            """
            SELECT fe.student_id, fe.encoding, s.name
            FROM face_encodings fe
            INNER JOIN students s ON s.id = fe.student_id
            """
        )
        rows = cursor.fetchall()
    finally:
        cursor.close()
        connection.close()

    if not rows:
        return jsonify({
            "matched": False,
            "message": "No face encodings are registered yet.",
        }), 404

    known_encodings = [json_to_encoding(row["encoding"]) for row in rows]
    best_index, confidence = best_face_match(known_encodings, candidate)

    if best_index is None:
        return jsonify({"matched": False, "message": "No matching face found."})

    matched_row = rows[best_index]

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

    connection = db_connection()
    cursor = connection.cursor(dictionary=True)

    try:
        cursor.execute(
            """
            SELECT fe.student_id, fe.encoding, s.name, s.roll_no
            FROM face_encodings fe
            INNER JOIN students s ON s.id = fe.student_id
            """
        )
        rows = cursor.fetchall()
    finally:
        cursor.close()
        connection.close()

    if not rows:
        return jsonify({
            "matches": [],
            "faces_detected": len(candidate_encodings),
            "message": "No face encodings are registered yet.",
        }), 404

    known_encodings = [json_to_encoding(row["encoding"]) for row in rows]
    matches = []
    matched_student_ids = set()

    for candidate in candidate_encodings:
        best_index, confidence = best_face_match(known_encodings, candidate)

        if best_index is None or confidence < FACE_MATCH_THRESHOLD:
            continue

        matched_row = rows[best_index]
        student_id = matched_row["student_id"]

        # Skip if this student was already matched by another face crop
        if student_id in matched_student_ids:
            continue

        matched_student_ids.add(student_id)
        matches.append({
            "student_id": student_id,
            "student_name": matched_row["name"],
            "roll_no": matched_row.get("roll_no", ""),
            "confidence": confidence,
        })

    return jsonify({
        "matches": matches,
        "faces_detected": len(candidate_encodings),
        "message": f"{len(matches)} student(s) recognized out of {len(candidate_encodings)} face(s) detected.",
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
