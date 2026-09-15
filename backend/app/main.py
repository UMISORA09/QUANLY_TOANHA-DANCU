import logging
from fastapi import FastAPI, Request, status
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.exceptions import RequestValidationError

from .core.config import settings
from .routers import auth, admin_amenities, meta

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("amenity_api")

app = FastAPI(
    title="Smart Apartment Management - Amenity API",
    description="Hệ thống Quản lý Danh mục Tiện ích, Cấu hình Sức chứa và Slot Tối đa dành cho Ban Quản Lý (Admin).",
    version="1.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
)

# CORS Middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# UTF-8 Charset Header Middleware
@app.middleware("http")
async def ensure_utf8_charset_middleware(request: Request, call_next):
    response = await call_next(request)
    ctype = response.headers.get("content-type", "")
    if "application/json" in ctype and "charset" not in ctype:
        response.headers["content-type"] = "application/json; charset=utf-8"
    return response

# Exception Handlers
@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    errors = []
    for err in exc.errors():
        loc = " -> ".join([str(l) for l in err.get("loc", [])])
        msg = err.get("msg", "Dữ liệu không hợp lệ")
        errors.append(f"{loc}: {msg}")
    
    return JSONResponse(
        status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
        content={
            "success": False,
            "message": "Dữ liệu yêu cầu không hợp lệ. Vui lòng kiểm tra lại các trường thông tin.",
            "error_code": "VALIDATION_ERROR",
            "details": errors,
        },
    )

@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Unhandled exception on {request.method} {request.url.path}: {exc}", exc_info=True)
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "success": False,
            "message": "Đã xảy ra lỗi máy chủ nội bộ. Vui lòng thử lại sau.",
            "error_code": "INTERNAL_SERVER_ERROR",
        },
    )

# Routers
app.include_router(auth.router, prefix=settings.API_V1_STR)
app.include_router(admin_amenities.router, prefix=settings.API_V1_STR)
app.include_router(meta.router, prefix=settings.API_V1_STR)

@app.get("/", tags=["Health"])
def root():
    return {
        "system": "Smart Apartment Management - Amenity API",
        "status": "online",
        "version": "1.0.0",
        "docs": "/docs",
    }

@app.get("/health", tags=["Health"])
def health():
    return {"status": "healthy"}
