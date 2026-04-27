import hmac
import hashlib
from datetime import datetime, timezone, timedelta
from typing import Tuple, Optional
from uuid import UUID

from src.config import get_settings


class TokenManager:
    def __init__(self, secret_key: str, max_ttl_seconds: int = 900):
        self.secret_key = secret_key.encode("utf-8")
        self.max_ttl_seconds = max_ttl_seconds

    def create_session_token(
        self, certificate_id: UUID, certificate_expiry: datetime
    ) -> Tuple[str, int]:
        """Create short-lived session token with real-time expiry bound"""
        now = datetime.now(timezone.utc)
        remaining = (certificate_expiry - now).total_seconds()

        # Token TTL = min(max_ttl, remaining certificate lifetime)
        ttl_seconds = max(1, min(self.max_ttl_seconds, remaining))
        token_expiry = now + timedelta(seconds=ttl_seconds)

        payload = f"{certificate_id}|{token_expiry.isoformat().replace('+', '_')}"
        signature = hmac.new(
            self.secret_key, payload.encode("utf-8"), hashlib.sha256
        ).hexdigest()

        token = f"{payload}|{signature}"
        return token, int(ttl_seconds)

    def validate_session_token(
        self, token: str
    ) -> Tuple[Optional[UUID], Optional[datetime], bool]:
        """Validate session token and return certificate_id and expiry"""
        try:
            parts = token.split("|")
            if len(parts) != 3:
                return None, None, False

            cert_id_str, expiry_iso, signature = parts
            token_expiry = datetime.fromisoformat(expiry_iso.replace('_', '+'))

            # Verify signature
            payload = f"{cert_id_str}|{expiry_iso}"
            expected = hmac.new(
                self.secret_key, payload.encode("utf-8"), hashlib.sha256
            ).hexdigest()

            if not hmac.compare_digest(expected, signature):
                return None, None, False

            # Check if token itself is expired
            if token_expiry < datetime.now(timezone.utc):
                return None, None, False

            return UUID(cert_id_str), token_expiry, True

        except Exception:
            return None, None, False


def get_token_manager() -> TokenManager:
    return TokenManager(get_settings().secret_key, get_settings().token_ttl_seconds)
