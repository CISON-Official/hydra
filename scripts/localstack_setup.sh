#!/bin/bash

set -e

if ! command -v aws &> /dev/null; then
    echo "Installing AWS CLI..."
    sudo apt update && sudo apt install -y curl unzip
    curl "https://amazonaws.com" -o "awscliv2.zip"
    unzip awscliv2.zip
    sudo ./aws/install
    rm -rf awscliv2.zip aws
fi


export AWS_ACCESS_KEY_ID=test
export AWS_SECRET_ACCESS_KEY=test
export AWS_DEFAULT_REGION=us-east-1
export ENDPOINT_URL="https://localhost:4566"


echo "Starting LocalStack container..."
docker run -d --name localstack_main \
  -p 4566:4566 \
  -e PERSISTENCE=1 \
  -e AWS_DEFAULT_REGION=$AWS_DEFAULT_REGION \
  -v ./localstack_data:/var/lib/localstack \
  -v /var/run/docker.sock:/var/run/docker.sock \
  localstack/localstack

echo "Waiting for LocalStack to initialize..."
until curl -s -k $ENDPOINT_URL/_localstack/health | grep -q '"s3": "available"'; do
  sleep 2
done


echo "Creating S3 bucket: my-test-bucket"
aws --endpoint-url=$ENDPOINT_URL s3 mb s3://my-test-bucket --no-verify-ssl

echo "Triggering manual state backup..."
curl -s -k -X POST $ENDPOINT_URL/_localstack/state/s3/save

echo "Syncing bucket files to local backup folder..."
mkdir -p ./s3_backups
aws --endpoint-url=$ENDPOINT_URL s3 sync s3://my-test-bucket ./s3_backups --no-verify-ssl

echo "Setup complete! Access your bucket at $ENDPOINT_URL"