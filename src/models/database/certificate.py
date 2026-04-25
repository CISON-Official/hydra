# src/models/database/certificate.py (updated)

from sqlalchemy import String, DateTime, Boolean, Index, Text, Integer
from sqlalchemy.dialects.postgresql import UUID, JSONB
from sqlalchemy.orm import Mapped, mapped_column, DeclarativeBase
from sqlalchemy.ext.asyncio import AsyncAttrs
from sqlalchemy.schema import UniqueConstraint
from datetime import datetime, timezone
import uuid


class Base(AsyncAttrs, DeclarativeBase):
    pass


class Certificate(Base):
    __tablename__ = "certificates"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    issuer_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), nullable=False, index=True
    )
    holder_email: Mapped[str] = mapped_column(String(255), nullable=False, index=True)
    holder_name: Mapped[str] = mapped_column(String(255), nullable=False)
    certificate_title: Mapped[str] = mapped_column(String(500), nullable=False)

    # File tracking
    current_version: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    file_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    s3_key: Mapped[str] = mapped_column(String(512), nullable=False)
    file_size_bytes: Mapped[int] = mapped_column(Integer, nullable=False)
    content_type: Mapped[str] = mapped_column(
        String(100), nullable=False, default="application/pdf"
    )

    # QR Code (persists across versions unless regenerated)
    qr_nonce: Mapped[str | None] = mapped_column(String(64), unique=True)

    # Status
    expires_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, index=True
    )
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    revocation_reason: Mapped[str | None] = mapped_column(String(500))

    # Metadata
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=lambda: datetime.now(timezone.utc)
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
    )
    last_updated_by: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True))
    update_reason: Mapped[str | None] = mapped_column(String(500))

    # Version history tracking
    version_history: Mapped[dict | None] = mapped_column(JSONB, default=list)

    __table_args__ = (
        Index("idx_certificate_expiry_valid", "expires_at", "revoked_at"),
        Index("idx_certificate_issuer_active", "issuer_id", "revoked_at"),
        Index("idx_certificate_version", "id", "current_version"),
    )


class CertificateVersion(Base):
    """Track individual versions of certificates"""

    __tablename__ = "certificate_versions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    certificate_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), nullable=False, index=True
    )
    version_number: Mapped[int] = mapped_column(Integer, nullable=False)
    file_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    s3_key: Mapped[str] = mapped_column(String(512), nullable=False)
    file_size_bytes: Mapped[int] = mapped_column(Integer, nullable=False)
    updated_by: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    update_reason: Mapped[str] = mapped_column(String(500))
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=lambda: datetime.now(timezone.utc)
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
    )

    __table_args__ = (
        Index("idx_cert_version_cert", "certificate_id", "version_number"),
        UniqueConstraint("certificate_id", "version_number", name="uq_cert_version"),
    )
