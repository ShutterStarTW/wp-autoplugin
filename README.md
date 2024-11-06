# WP-Autoplugin

<p align="center">
  <img src="https://wp-autoplugin.com/wp-autoplugin-logo.png" alt="WP-Autoplugin Logo" width="128">
</p>

WP-Autoplugin is a free WordPress plugin that uses AI to assist in generating, fixing, and extending plugins on-demand. It enables users to quickly create functional plugins from simple descriptions, addressing specific needs without unnecessary bloat.

- Generate plugins using AI
- Fix and extend existing plugins
- Full control over the generation process
- Support for multiple AI models (OpenAI, Anthropic, Google Gemini)
- View the list of generated plugins for easy management

---

WP-Autoplugin offers practical solutions for various WordPress development scenarios:

- **Lightweight Alternatives**: Create simple, focused plugins to replace large, feature-heavy plugins that may slow down your site or include unnecessary features and advertisements.
- **Custom Solutions**: Develop site-specific plugins tailored to your unique requirements, eliminating the need for complex workarounds or multiple plugins.
- **Developer Foundations**: Generate solid base plugins that developers can extend and build upon, streamlining the process of creating complex, custom plugins.

## Plugin Highlights

- **Completely Free**: No premium version, no ads, no account required.
- **Open Source**: Contributions are welcome.
- **Privacy-Focused**: No data collection or external communication (except for the AI API you choose).
- **BYOK (Bring Your Own Key)**: Use your own OpenAI, Anthropic, or Google AI Studio API key.

## How It Works

1. **Describe Your Plugin**: Provide a description of the plugin you want to create.
2. **AI Generation**: WP-Autoplugin uses AI to generate a development plan and write the code.
3. **Review and Install**: Review the generated plan and code, make any necessary changes, and install the plugin with a single click.

You can also use WP-Autoplugin to **fix bugs** or **add new features** to existing plugins you've created with the tool.

## Supported Models

WP-Autoplugin supports various models:

- GPT-3.5 Turbo
- GPT-4
- GPT-4o
- GPT-4o mini
- Claude 3.5 Sonnet
- Claude 3.5 Haiku
- Claude 3 Haiku
- Claude 3 Sonnet
- Claude 3 Opus
- xAI Grok-beta
- Google Gemini Flash 1.5
- Google Gemini Pro 1.5
- Google Gemini Pro 1.0

We recommend using the **Claude 3.5 Sonnet** model for optimal results. While WP-Autoplugin is free to use, you may need to pay for API usage based on your chosen model.

## BYOK (Bring Your Own Key)

To use WP-Autoplugin, you'll need an API key from OpenAI, Anthropic, or Google AI Studio. Insert your key in the plugin settings to get started. Your API key remains on your server and is not shared with anyone.

Google AI Studio keys are currently free to use, while OpenAI and Anthropic keys require payment. Refer to the respective websites for pricing information.

## AI-Generated Plugins

Plugins created by WP-Autoplugin are standard WordPress plugins:

- They function independently and will continue to work even if WP-Autoplugin is deactivated or deleted.
- You can install them on other WordPress sites without WP-Autoplugin.
- While WP-Autoplugin provides a convenient listing screen for generated plugins, they can also be managed from the standard WordPress Plugins screen.

## Code Quality and Security

WP-Autoplugin aims to generate code that adheres to WordPress coding standards. However, it's important to treat AI-generated code with the same caution you would apply to any third-party code. We strongly recommend:

- Reviewing and testing all generated code before use in a production environment.
- Conducting thorough testing on a staging site before deployment.
- Considering a professional security audit for critical applications.

## Limitations

WP-Autoplugin has some limitations to be aware of:
- Works best with simple plugins; may struggle with complex, multi-feature plugins.
- Generates single-file plugins only.
- Plugins require thorough testing before use on production sites.

## Installation

1. Download the latest release from the [Releases](https://github.com/WP-Autoplugin/wp-autoplugin/releases) page.
2. Upload the plugin zip file through the 'Plugins' screen in WordPress, or unzip the file and upload the `wp-autoplugin` folder to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' screen in WordPress.
4. Go to the WP-Autoplugin settings page and enter your API key(s).
5. Choose your preferred AI model in the settings.
6. Start generating plugins!

## Screenshots

<details>
<summary>Click to view screenshots</summary>

1. Generate plugin form
![Generate plugin form](https://wp-autoplugin.com/screenshot-1.png)

2. Review generated plan
![Review generated plan](https://wp-autoplugin.com/screenshot-2.png)

3. Review generated code
![Review generated code](https://wp-autoplugin.com/screenshot-3.png)

4. Autoplugins listing screen
![Autoplugins listing screen](https://wp-autoplugin.com/screenshot-4.png)

5. Fix plugin form
![Fix plugin form](https://wp-autoplugin.com/screenshot-5.png)

</details>

Or watch the [WP-Autoplugin demo video on Youtube](https://www.youtube.com/watch?v=b36elwTLfa4) that shows how it generates a plugin and fixes a bug.

## Licensing

WP-Autoplugin is licensed under the GPLv3 or later.

## Changelog

### 1.1
- Added support for Claude 3.5 Haiku
- Added support for xAI and its only current model, Grok-beta
- Fixed an issue that prevented the code from being edited in the code editor
- Fixed wrong model name for "chatgpt-4o-latest"
- Improved the generator prompt for better code generation on lower-end models
- Improved inline documentation

### 1.0.6
- Fixed Github updater class
- Fixed a few bugs
- Added i18n support and Hungarian translation
- Adjusted prompt for better code generation
- Added docblocks to the code

### 1.0.5
- Added update from GitHub feature
- Reorganized files and folders and added Composer support
- Fixed small bugs

### 1.0.4
- Added support for Google Gemini models

### 1.0.3
- Added support for gpt-4o-2024-08-06
- Cleaned up prompts for better readability

### 1.0.2
- Added support for GPT-4o mini
- Added support for the high-limit (8192 tokens) version of Claude 3.5 Sonnet

### 1.0.1
- Fixed max_tokens value for OpenAI models

### 1.0
- Initial release
