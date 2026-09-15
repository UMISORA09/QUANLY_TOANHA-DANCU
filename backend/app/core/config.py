import os
from pydantic import BaseModel
from dotenv import load_dotenv

load_dotenv()

class Settings(BaseModel):
    PROJECT_NAME: str = "Smart Apartment Management - Amenity System"
    API_V1_STR: str = "/api/v1"
    
    # Database Settings
    DB_DRIVER: str = os.getenv("DB_DRIVER", "ODBC Driver 17 for SQL Server")
    DB_SERVER: str = os.getenv("DB_SERVER", "localhost\\SQLEXPRESS")
    DB_PORT: str = os.getenv("DB_MSSQL_PORT", "1433")
    DB_DATABASE: str = os.getenv("DB_MSSQL_DATABASE", "CSDL_CHUNGCU&DANCU")
    DB_USERNAME: str = os.getenv("DB_MSSQL_USER", "")
    DB_PASSWORD: str = os.getenv("DB_MSSQL_PASSWORD", "")
    DB_TRUSTED_CONNECTION: str = os.getenv("DB_MSSQL_TRUSTED_CONNECTION", os.getenv("DB_TRUSTED_CONNECTION", "yes"))
    DB_TRUST_SERVER_CERTIFICATE: str = os.getenv("DB_TRUST_SERVER_CERTIFICATE", "yes")
    
    # JWT Settings
    JWT_SECRET_KEY: str = os.getenv("JWT_SECRET_KEY", "smart-cassavas-secure-jwt-secret-key-2026-production")
    JWT_ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 60 * 24  # 1 day
    ADMIN_DEFAULT_PASSWORD: str = os.getenv("ADMIN_DEFAULT_PASSWORD", "Cassavas@2026")
    
    # CORS
    CORS_ORIGINS: list[str] = [
        "http://localhost:8000",
        "http://127.0.0.1:8000",
        "http://localhost:5173",
        "http://127.0.0.1:5173",
        "http://localhost:8001",
        "http://127.0.0.1:8001",
    ]

    def get_odbc_connection_string(self) -> str:
        # Build ODBC connection string
        server = self.DB_SERVER
        # If server is purely IP/hostname and has custom port without instance name
        if "\\" not in server and self.DB_PORT and self.DB_PORT != "1433" and "," not in server:
            server = f"{server},{self.DB_PORT}"
            
        parts = [
            f"Driver={{{self.DB_DRIVER}}}",
            f"Server={server}",
            f"Database={self.DB_DATABASE}",
            f"TrustServerCertificate={self.DB_TRUST_SERVER_CERTIFICATE}",
        ]
        if self.DB_TRUSTED_CONNECTION.lower() in ("yes", "true", "1"):
            parts.append("Trusted_Connection=yes")
        else:
            parts.append(f"UID={self.DB_USERNAME}")
            parts.append(f"PWD={self.DB_PASSWORD}")
            
        return ";".join(parts) + ";"

settings = Settings()
