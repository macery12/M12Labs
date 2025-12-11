#!/bin/bash
# Script to validate the OpenAPI specification

DOCS_DIR="public/docs"
OPENAPI_FILE="$DOCS_DIR/openapi.yaml"

echo "Validating OpenAPI specification..."

# Check if file exists
if [ ! -f "$OPENAPI_FILE" ]; then
    echo "❌ Error: OpenAPI file not found at $OPENAPI_FILE"
    exit 1
fi

# Validate YAML syntax
if command -v python3 &> /dev/null; then
    if python3 -c "import yaml; yaml.safe_load(open('$OPENAPI_FILE'))" 2>&1; then
        echo "✓ YAML syntax is valid"
    else
        echo "❌ YAML syntax error"
        exit 1
    fi
else
    echo "⚠ Python3 not found, skipping YAML syntax validation"
fi

# Count endpoints
ENDPOINT_COUNT=$(grep -c '^\  /' "$OPENAPI_FILE")
echo "✓ Found $ENDPOINT_COUNT API endpoints documented"

# Check for required sections
REQUIRED_SECTIONS=("openapi:" "info:" "paths:" "components:" "servers:")
for section in "${REQUIRED_SECTIONS[@]}"; do
    if grep -q "^$section" "$OPENAPI_FILE"; then
        echo "✓ Section '$section' present"
    else
        echo "❌ Missing required section: $section"
        exit 1
    fi
done

echo ""
echo "✅ OpenAPI specification validation passed!"
echo ""
echo "Documentation available at:"
echo "  - Swagger UI: /docs/index.html"
echo "  - ReDoc: /docs/redoc.html"
echo "  - OpenAPI Spec: /docs/openapi.yaml"
