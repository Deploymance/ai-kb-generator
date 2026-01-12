# AI KB Generator - WHMCS Addon

Generate Knowledge Base articles from support tickets using AI. Automatically suggests titles, categories, and tags.

## Features

- **One-Click KB Generation**: Generate KB articles directly from any ticket
- **AI-Powered Content**: Uses Google Gemini AI to create professional KB articles
- **Smart Suggestions**: AI suggests appropriate category, title, and tags
- **Automatic Queue**: Closed tickets are automatically queued for KB review
- **Replace Existing**: Update existing KB articles with improved content
- **Configurable Retention**: Keep tickets in queue for a configurable number of days
- **Multi-Model Support**: Choose from 4 Gemini models based on your needs

## Installation

1. Upload the `ai_kb_generator` folder to `/modules/addons/`
2. Go to WHMCS Admin > System Settings > Addon Modules
3. Find "AI KB Generator" and click "Activate"
4. Click "Configure" and enter:
   - Deploymance License Key
   - Google Gemini API Key

## Configuration Options

| Setting | Description | Default |
|---------|-------------|---------|
| License Key | Your Deploymance license key | Required |
| Gemini API Key | Your Google Gemini API key | Required |
| Gemini Model | AI model to use | gemini-2.5-flash |
| Retention Days | Days to keep tickets in queue | 31 |
| Auto-Queue Closed | Auto-add closed tickets to queue | Yes |
| Minimum Replies | Min replies for auto-queue | 2 |

## Usage

### From Ticket View

1. Open any support ticket
2. Click the "Create KB Article" button
3. Click "Generate with AI" to create content
4. Edit the title, content, category, and tags as needed
5. Choose to replace an existing article or create new
6. Click "Save KB Article"

### From Admin Module

1. Go to Addons > AI KB Generator
2. View all queued tickets
3. Click "Generate KB" on any ticket
4. Review and save the generated article

## Requirements

- WHMCS 8.0 or higher
- PHP 7.4 or higher
- Deploymance License Key (https://deploymance.com/addons)
- Google Gemini API Key (https://aistudio.google.com/app/apikey)

## API Pricing (as of January 2026)

| Model | Input | Output |
|-------|-------|--------|
| gemini-2.5-flash | $0.30/1M tokens | $2.50/1M tokens |
| gemini-2.5-pro | $1.25/1M tokens | $10.00/1M tokens |
| gemini-2.0-flash | $0.10/1M tokens | $0.40/1M tokens |
| gemini-2.5-flash-lite | $0.10/1M tokens | $0.40/1M tokens |

## License

Deploymance Proprietary License - See LICENSE file for details.

## Support

- Website: https://deploymance.com
- Documentation: https://deploymance.com/docs/ai-kb-generator
