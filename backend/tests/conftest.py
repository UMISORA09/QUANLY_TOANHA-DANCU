import sys
import os
import pytest
from fastapi.testclient import TestClient

# Add backend directory to sys.path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from app.main import app
from app.core.database import SessionLocal
from app.routers.auth import ensure_seed_users

@pytest.fixture(scope="session")
def client():
    with TestClient(app) as c:
        yield c

@pytest.fixture(scope="session")
def db_session():
    db = SessionLocal()
    try:
        ensure_seed_users(db)
        yield db
    finally:
        db.close()

@pytest.fixture(scope="session")
def admin_token(client):
    res = client.post("/api/v1/auth/login", json={
        "identifier": "admin@smartcassavas.vn",
        "password": "Cassavas@2026"
    })
    assert res.status_code == 200, f"Failed to login as admin: {res.text}"
    return res.json()["access_token"]

@pytest.fixture(scope="session")
def resident_token(client):
    res = client.post("/api/v1/auth/login", json={
        "identifier": "cudan@smartcassavas.vn",
        "password": "Cassavas@2026"
    })
    assert res.status_code == 200, f"Failed to login as resident: {res.text}"
    return res.json()["access_token"]

@pytest.fixture
def admin_headers(admin_token):
    return {"Authorization": f"Bearer {admin_token}"}

@pytest.fixture
def resident_headers(resident_token):
    return {"Authorization": f"Bearer {resident_token}"}
