"""
ml_api.py  —  BikeValue ML Bridge
======================================
MATCHES YOUR EXACT TRAINED MODEL:
  Features: bike_name, kms_driven, owner, age, city,
            engine_capacity, accident_count, brand, accident_history

LOCAL: python ml_api.py
RENDER: runs via gunicorn automatically

PKL FILES: Too large for Git? Set these environment variables in Render:
  MODEL_PRICE_ID    = your Google Drive file ID for model_price.pkl
  MODEL_ADJUSTED_ID = your Google Drive file ID for model_adjusted.pkl
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import joblib
import pandas as pd
import numpy as np
import os

app = Flask(__name__)
CORS(app)

BASE = os.path.dirname(os.path.abspath(__file__))

# ── AUTO-DOWNLOAD PKL FILES FROM GOOGLE DRIVE IF MISSING ──────────────────────
def download_models_if_needed():
    try:
        import gdown
    except ImportError:
        print("⚠  gdown not installed — skipping auto-download")
        return

    files = {
        'model_price.pkl':    os.environ.get('MODEL_PRICE_ID', ''),
        'model_adjusted.pkl': os.environ.get('MODEL_ADJUSTED_ID', ''),
    }
    for fname, fid in files.items():
        path = os.path.join(BASE, fname)
        if not os.path.exists(path):
            if fid:
                print(f"⬇  Downloading {fname} from Google Drive...")
                try:
                    gdown.download(f'https://drive.google.com/uc?id={fid}', path, quiet=False)
                    print(f"✅ {fname} downloaded successfully")
                except Exception as e:
                    print(f"❌ Failed to download {fname}: {e}")
            else:
                print(f"⚠  {fname} not found and no Google Drive ID set for it.")
                print(f"   Set environment variable MODEL_PRICE_ID or MODEL_ADJUSTED_ID in Render.")
        else:
            print(f"✅ {fname} already present")

download_models_if_needed()
# ──────────────────────────────────────────────────────────────────────────────

model_price    = None
model_adjusted = None

price_path    = os.path.join(BASE, 'model_price.pkl')
adjusted_path = os.path.join(BASE, 'model_adjusted.pkl')

if os.path.exists(price_path):
    model_price = joblib.load(price_path)
    print("✅ model_price loaded")
else:
    print(f"⚠  model_price.pkl not found at {price_path}")

if os.path.exists(adjusted_path):
    model_adjusted = joblib.load(adjusted_path)
    print("✅ model_adjusted loaded")
else:
    print(f"⚠  model_adjusted.pkl not found at {adjusted_path}")


@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'model_price_loaded':    model_price    is not None,
        'model_adjusted_loaded': model_adjusted is not None,
    })


@app.route('/predict', methods=['POST'])
def predict():
    if model_price is None:
        return jsonify({'error': 'model_price.pkl not loaded. Check Render env vars MODEL_PRICE_ID and MODEL_ADJUSTED_ID.'}), 503

    data = request.get_json()

    accident_count   = float(data.get('accident_count', 0))
    accident_history = data.get('accident_history', 'none').strip().lower()

    if accident_count == 0:
        accident_history = 'none'

    base_input_df = pd.DataFrame({
        'bike_name':        [data.get('bike_name', '').strip().lower()],
        'kms_driven':       [float(data.get('kms_driven', 0))],
        'owner':            [float(data.get('owner', 1))],
        'age':              [float(data.get('age', 0))],
        'city':             [data.get('city', '').strip().lower()],
        'engine_capacity':  [float(data.get('engine_capacity', 0))],
        'accident_count':   [0],
        'brand':            [data.get('brand', '').strip().lower()],
        'accident_history': ['none'],
    })

    adjusted_input_df = pd.DataFrame({
        'bike_name':        [data.get('bike_name', '').strip().lower()],
        'kms_driven':       [float(data.get('kms_driven', 0))],
        'owner':            [float(data.get('owner', 1))],
        'age':              [float(data.get('age', 0))],
        'city':             [data.get('city', '').strip().lower()],
        'engine_capacity':  [float(data.get('engine_capacity', 0))],
        'accident_count':   [accident_count],
        'brand':            [data.get('brand', '').strip().lower()],
        'accident_history': [accident_history],
    })

    try:
        predicted_price = float(model_price.predict(base_input_df)[0])
        result = {
            'status':          'success',
            'predicted_price': round(predicted_price, 2),
            'accident_count':  accident_count,
        }

        if accident_count > 0 and model_adjusted is not None:
            predicted_adjusted = float(model_adjusted.predict(adjusted_input_df)[0])
            result['predicted_adjusted'] = round(predicted_adjusted, 2)
            result['accident_impact']    = round(predicted_price - predicted_adjusted, 2)

        return jsonify(result)

    except Exception as e:
        return jsonify({'error': str(e)}), 400


if __name__ == '__main__':
    print("\n🏍  BikeValue ML API running at http://localhost:5000")
    print("   POST /predict  — get price prediction")
    print("   GET  /health   — check model status\n")
    app.run(debug=True, host='0.0.0.0', port=5000)
