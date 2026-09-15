import sys
import io
from backend.app.core.database import SessionLocal
from sqlalchemy import text

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
db = SessionLocal()

print("--- AMENITIES WITH '?' ---")
rows = db.execute(text("SELECT id, amenity_name, location_detail, rules_and_regulations FROM amenities WHERE amenity_name LIKE '%?%' OR location_detail LIKE '%?%' OR rules_and_regulations LIKE '%?%'")).fetchall()
for r in rows:
    print(r)

print("\n--- CATEGORIES WITH '?' ---")
rows2 = db.execute(text("SELECT id, category_name, description FROM amenity_categories WHERE category_name LIKE '%?%' OR description LIKE '%?%'")).fetchall()
for r in rows2:
    print(r)

print("\n--- TIME SLOTS WITH '?' ---")
rows3 = db.execute(text("SELECT id, slot_label FROM amenity_time_slots WHERE slot_label LIKE '%?%'")).fetchall()
for r in rows3:
    print(r)

print("\n--- BLACKOUTS WITH '?' ---")
rows4 = db.execute(text("SELECT id, reason FROM amenity_blackouts WHERE reason LIKE '%?%'")).fetchall()
for r in rows4:
    print(r)

db.close()
