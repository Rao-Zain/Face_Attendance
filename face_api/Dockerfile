FROM python:3.10-slim

# Install system dependencies needed by dlib and OpenCV
RUN apt-get update && apt-get install -y \
    cmake \
    build-essential \
    libopenblas-dev \
    liblapack-dev \
    libx11-dev \
    libboost-python-dev \
    libboost-thread-dev \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy and install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy all app files
COPY . .

# Expose port 7860 (Hugging Face requires this exact port)
EXPOSE 7860

# Start the app
CMD ["gunicorn", "--bind", "0.0.0.0:7860", "--timeout", "120", "app:app"]