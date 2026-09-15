import uuid
from typing import List, Optional
from fastapi import APIRouter, Depends
from pydantic import BaseModel, ConfigDict
from sqlalchemy.orm import Session

from ..core.database import get_db
from ..repositories.amenity_repo import AmenityRepository

router = APIRouter(prefix="/meta", tags=["Metadata"])

class BlockOption(BaseModel):
    id: uuid.UUID
    block_code: str
    block_name: str

    model_config = ConfigDict(from_attributes=True)

@router.get("/blocks", response_model=List[BlockOption], summary="Danh sách tòa nhà phục vụ lựa chọn")
def list_blocks(db: Session = Depends(get_db)):
    blocks = AmenityRepository.get_blocks(db)
    return [
        BlockOption(
            id=b.id,
            block_code=b.block_code,
            block_name=b.block_name,
        )
        for b in blocks
    ]
