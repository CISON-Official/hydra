import hmac
import hashlib
import secrets
from typing import Tuple
from uuid import UUID

from src.config import get_settings


class HMACHandler:
    def __init__(self, secret_key: str):
        self.secret_key = secret_key.encode("utf-8")

    def generate_signature(self, certificate_id: UUID, nonce: str) -> str:
        """Generate HMAC signature for QR URL"""
        payload = f"{certificate_id}|{nonce}"
        signature = hmac.new(
            self.secret_key, payload.encode("utf-8"), hashlib.sha256
        ).hexdigest()
        return signature

    def verify_signature(
        self, certificate_id: UUID, nonce: str, signature: str
    ) -> bool:
        """Verify HMAC signature"""
        expected = self.generate_signature(certificate_id, nonce)
        return hmac.compare_digest(expected, signature)

    def generate_nonce(self) -> str:
        """Generate cryptographically secure nonce for QR"""
        return secrets.token_urlsafe(32)


def get_hmac_handler() -> HMACHandler:
    return HMACHandler(get_settings().secret_key)
