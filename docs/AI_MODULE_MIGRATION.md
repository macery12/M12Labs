# AI Module Migration Guide

## Overview

The AI module has been refactored to use OpenAI-compatible endpoints instead of the proprietary Gemini API. This change provides greater flexibility and allows administrators to use various AI providers.

## What Changed

### Backend Changes

1. **New OpenAI Service** (`app/Services/AI/OpenAIService.php`)
   - Replaced Gemini API client with a custom service using Guzzle HTTP client
   - Uses standard OpenAI chat completions API format
   - Supports any OpenAI-compatible endpoint

2. **Updated Controllers**
   - `IntelligenceController.php`: Now uses dependency injection for `OpenAIService`
   - `AIController.php`: Updated to use the new service instead of Gemini client

3. **New Configuration Options**
   - `modules.ai.endpoint`: Base URL for the AI API (default: `https://api.openai.com/v1`)
   - `modules.ai.model`: Model name to use (default: `gpt-3.5-turbo`)
   - Existing `modules.ai.key`: API key for authentication
   - Existing `modules.ai.enabled`: Enable/disable AI module
   - Existing `modules.ai.user_access`: Allow non-admin users to access AI

### Frontend Changes

1. **Updated Configuration Interface**
   - Added endpoint URL configuration field
   - Added model selection input
   - Updated help text to reference OpenAI-compatible APIs instead of Gemini

2. **Updated Component Text**
   - Replaced "Gemini" references with "OpenAI-compatible endpoints"
   - Updated links to point to OpenAI documentation

## Supported AI Providers

The refactored AI module now supports any OpenAI-compatible API, including:

- **OpenAI** (https://api.openai.com/v1)
  - Models: gpt-3.5-turbo, gpt-4, gpt-4-turbo, etc.
  
- **Azure OpenAI Service**
  - Endpoint format: `https://<resource-name>.openai.azure.com/openai/deployments/<deployment-id>`
  
- **LocalAI** (Self-hosted)
  - Endpoint: Your LocalAI instance URL
  
- **Ollama** (with OpenAI compatibility)
  - Endpoint: `http://localhost:11434/v1` (or your Ollama server URL)
  - Models: llama2, mistral, codellama, etc.
  
- **Other OpenAI-compatible services**
  - Any service that implements the OpenAI chat completions API

## Migration Steps

### For Existing Installations

If you were previously using the Gemini-based AI module:

1. **Backup your existing configuration**
   ```bash
   # Note your existing AI settings from the admin panel
   ```

2. **Update your environment**
   - No environment changes needed - settings are stored in the database

3. **Reconfigure AI Settings**
   - Navigate to Admin Panel → AI → Settings
   - Enter your new API key (OpenAI or compatible provider)
   - Set the endpoint URL (e.g., `https://api.openai.com/v1`)
   - Set the model name (e.g., `gpt-3.5-turbo`)
   - Save changes

### For Fresh Installations

1. Navigate to Admin Panel → AI
2. Enable the AI module
3. Configure the following:
   - **API Key**: Your OpenAI API key or compatible provider key
   - **Endpoint URL**: Base URL for the API (default is OpenAI)
   - **Model**: Model name to use (e.g., `gpt-3.5-turbo`)
4. Save and test the configuration

## API Request Format

The service uses the following request format (OpenAI chat completions):

```json
{
  "model": "gpt-3.5-turbo",
  "messages": [
    {
      "role": "system",
      "content": "You are a helpful assistant for a game server hosting panel..."
    },
    {
      "role": "user",
      "content": "User's query here"
    }
  ],
  "max_tokens": 1000,
  "temperature": 0.7
}
```

## Configuration Examples

### OpenAI
```
Endpoint: https://api.openai.com/v1
Model: gpt-3.5-turbo
API Key: sk-...
```

### LocalAI
```
Endpoint: http://localhost:8080/v1
Model: gpt-3.5-turbo (or your LocalAI model name)
API Key: (optional, depends on your setup)
```

### Ollama
```
Endpoint: http://localhost:11434/v1
Model: llama2
API Key: (not required for local Ollama)
```

## Troubleshooting

### "Failed to communicate with AI service" Error

1. Check that your endpoint URL is correct and accessible
2. Verify your API key is valid
3. Ensure the model name exists for your provider
4. Check server logs for detailed error messages

### Rate Limiting

Different providers have different rate limits:
- OpenAI: Varies by tier (see OpenAI documentation)
- LocalAI/Ollama: Based on your hardware and configuration
- Other providers: Check with your provider

### Model Not Found

Ensure the model name matches exactly what your provider supports:
- OpenAI: `gpt-3.5-turbo`, `gpt-4`, etc.
- Ollama: `llama2`, `mistral`, `codellama`, etc.
- Check your provider's model list

## Development Notes

### Testing Locally with Ollama

1. Install Ollama: https://ollama.ai/
2. Pull a model: `ollama pull llama2`
3. Configure Jexactyl:
   - Endpoint: `http://localhost:11434/v1`
   - Model: `llama2`
   - API Key: (leave empty)

### Custom System Prompts

The system prompt is defined in `OpenAIService.php`:
```php
'content' => 'You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses.',
```

You can customize this to better suit your panel's needs.

## Security Considerations

1. **API Keys**: Store securely in the database, never expose in frontend code
2. **Endpoint URLs**: Validate URLs to prevent SSRF attacks
3. **Rate Limiting**: Consider implementing rate limiting to prevent abuse
4. **User Access**: Use the `user_access` setting to control who can use AI features

## Future Enhancements

Potential improvements for future versions:
- Support for streaming responses
- Conversation history/context
- Multiple model selection per feature
- Custom system prompts per feature
- Token usage tracking and limits
- Response caching

## Support

For issues or questions:
- GitHub Issues: https://github.com/Jexactyl/Jexactyl/issues
- Discord: https://discord.gg/qttGR4Z5Pk
