#!/bin/bash
# Strip unused AWS SDK services — keep only what SPIP DSQL needs
set -e

VENDOR_DIR="${1:-vendor}"
SDK_DATA="$VENDOR_DIR/aws/aws-sdk-php/src/data"
SDK_SRC="$VENDOR_DIR/aws/aws-sdk-php/src"

if [ ! -d "$SDK_DATA" ]; then
    echo "AWS SDK not found at $SDK_DATA"
    exit 0
fi

BEFORE=$(du -sm "$VENDOR_DIR/aws" | cut -f1)

# Services to keep: what the bare platform uses (dsql, dynamodb, s3, ssm, sts for
# credentials) + SES ("email" is SES's SDK data-dir name) for optional transactional mail.
KEEP_SERVICES="dsql|dynamodb|email|s3|ses|ssm|sts"

# Remove unused service data directories
for dir in "$SDK_DATA"/*/; do
    service=$(basename "$dir")
    if ! echo "$service" | grep -qE "^($KEEP_SERVICES)$"; then
        rm -rf "$dir"
    fi
done

# Remove unused service PHP classes (src/<ServiceName>/ directories)
for dir in "$SDK_SRC"/*/; do
    dirname=$(basename "$dir")
    # Skip non-service directories
    case "$dirname" in
        data|Api|Arn|ClientSideMonitoring|Credentials|Crypto|DefaultsMode|Endpoint*|Exception|Handler*|Multipart|Retry|Signature|Token|DSQL|DynamoDb|S3|Ses|Ssm|Sts) continue ;;
    esac
    # If it's a service directory (has a Client.php), remove if not needed
    if [ -f "$dir/${dirname}Client.php" ]; then
        service_lower=$(echo "$dirname" | tr '[:upper:]' '[:lower:]')
        if ! echo "$service_lower" | grep -qE "^($KEEP_SERVICES)$"; then
            rm -rf "$dir"
        fi
    fi
done

# Remove docs, tests, examples from all vendor
find "$VENDOR_DIR" -name "*.md" -delete 2>/dev/null || true
find "$VENDOR_DIR" -name "CHANGELOG*" -delete 2>/dev/null || true
find "$VENDOR_DIR" -name "LICENSE*" -delete 2>/dev/null || true
find "$VENDOR_DIR" -name "README*" -delete 2>/dev/null || true
find "$VENDOR_DIR" -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
find "$VENDOR_DIR" -type d -name "Tests" -exec rm -rf {} + 2>/dev/null || true
find "$VENDOR_DIR" -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
find "$VENDOR_DIR" -type d -name "docs" -exec rm -rf {} + 2>/dev/null || true
find "$VENDOR_DIR" -type d -name "examples" -exec rm -rf {} + 2>/dev/null || true

AFTER=$(du -sm "$VENDOR_DIR/aws" | cut -f1)
echo "AWS SDK: ${BEFORE}MB → ${AFTER}MB (saved $((BEFORE - AFTER))MB)"
