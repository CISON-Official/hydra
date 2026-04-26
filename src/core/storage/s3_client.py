import hashlib
from typing import AsyncGenerator, BinaryIO, Tuple
from datetime import datetime, timezone

import aioboto3
from botocore.exceptions import ClientError

from src.config import Settings


class S3Client:
    def __init__(
        self,
        endpoint: str,
        access_key: str,
        secret_key: str,
        bucket: str,
        secure: bool = False,
        region: str = "us-east-1",
    ):
        self.endpoint = endpoint
        self.access_key = access_key
        self.secret_key = secret_key
        self.bucket = bucket
        self.secure = secure
        self.region = region
        self.session = aioboto3.Session()

    async def upload_file(
        self, file_data: bytes, key: str, content_type: str = "application/pdf"
    ) -> Tuple[str, int]:
        """Upload file to S3 and return (etag, file_size)"""
        async with self.session.client(
            "s3",
            endpoint_url=self.endpoint,
            aws_access_key_id=self.access_key,
            aws_secret_access_key=self.secret_key,
            use_ssl=self.secure,
            region_name=self.region,
        ) as s3:  # type: ignore
            print(f"Uploading file to S3: {key} using {self.endpoint}")
            response = await s3.put_object(
                Bucket=self.bucket,
                Key=key,
                Body=file_data,
                ContentType=content_type,
                Metadata={"uploaded_at": str(datetime.now(timezone.utc))},
            )
            return response["ETag"].strip('"'), len(file_data)

    async def get_file(self, key: str) -> bytes:
        """Retrieve file from S3"""
        async with self.session.client(
            "s3",
            endpoint_url=self.endpoint,
            aws_access_key_id=self.access_key,
            aws_secret_access_key=self.secret_key,
            use_ssl=self.secure,
            region_name=self.region,
        ) as s3:  # type: ignore
            response = await s3.get_object(Bucket=self.bucket, Key=key)
            return await response["Body"].read()

    async def delete_file(self, key: str):
        """Delete file from S3"""
        async with self.session.client(
            "s3",
            endpoint_url=self.endpoint,
            aws_access_key_id=self.access_key,
            aws_secret_access_key=self.secret_key,
            use_ssl=self.secure,
            region_name=self.region,
        ) as s3:  # type: ignore
            await s3.delete_object(Bucket=self.bucket, Key=key)

    async def file_exists(self, key: str) -> bool:
        """Check if file exists"""
        async with self.session.client(
            "s3",
            endpoint_url=self.endpoint,
            aws_access_key_id=self.access_key,
            aws_secret_access_key=self.secret_key,
            use_ssl=self.secure,
            region_name=self.region,
        ) as s3:  # type: ignore
            try:
                await s3.head_object(Bucket=self.bucket, Key=key)
                return True
            except ClientError:
                return False

    async def get_file_stream(
        self, key: str, chunk_size: int = 8192
    ) -> AsyncGenerator[bytes, None]:
        """
        Stream file from S3/MinIO in chunks.
        This is the method you need for streaming large files!

        Args:
            key: S3 object key
            chunk_size: Size of chunks to yield (bytes)

        Yields:
            Chunks of file data
        """
        async with self.session.client(
            "s3",
            endpoint_url=self.endpoint,
            aws_access_key_id=self.access_key,
            aws_secret_access_key=self.secret_key,
            use_ssl=self.secure,
            region_name=self.region,
        ) as s3:  # type: ignore
            try:
                response = await s3.get_object(Bucket=self.bucket, Key=key)
                body = response["Body"]

                # Read and yield chunks
                while True:
                    chunk = await body.read(chunk_size)
                    if not chunk:
                        break
                    yield chunk

            except ClientError as e:
                raise


def get_s3_client():
    setting = Settings()
    return S3Client(
        endpoint=setting.s3_endpoint,
        access_key=setting.s3_access_key,
        secret_key=setting.s3_secret_key,
        bucket=setting.s3_bucket,
        secure=setting.s3_secure,
        region=setting.s3_region,
    )
