# Support Ops & AI Auditor

**Version:** 10.0-OOP (Enterprise Edition)

A comprehensive WordPress plugin for 360° Support Operations Management with AI-powered ticket auditing, shift management, agent performance tracking, and knowledge gap detection.

## 🚀 Features

### Core Functionality

- **🤖 AI-Powered Ticket Auditing**
  - Automated ticket analysis using AI (Google Gemini)
  - Comprehensive scoring system (timing, resolution, communication)
  - Per-agent performance evaluation
  - Problem context detection and categorization

- **👥 Agent Management**
  - Agent profile management
  - Performance dashboards with detailed metrics
  - Score trends and team comparisons
  - Contribution tracking and rankings

- **⏰ Shift Management**
  - Flexible shift definitions (multiple types, colors, times)
  - Visual calendar interface
  - Bulk scheduling capabilities
  - Shift compliance tracking

- **📊 Analytics & Insights**
  - Agent performance analytics
  - Problem category analysis
  - Documentation gap detection
  - FAQ topic identification

- **🔌 REST API Integration**
  - Secure token-based authentication
  - N8N workflow integration
  - Webhook support for automated processing
  - Real-time status updates

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- FluentSupport plugin (for ticket management)
- MySQL 5.6 or higher
- N8N (optional, for automated AI processing)

## 🔧 Installation

1. **Download or Clone the Repository**
   ```bash
   git clone https://github.com/reachkamrul/ai-support-auditor.git
   cd ai-support-auditor
   ```

2. **Install to WordPress**
   - Copy the entire plugin folder to `/wp-content/plugins/`
   - Or use the `oop` branch: `git checkout oop`

3. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Find "Support Ops & AI Auditor"
   - Click "Activate"

4. **Database Setup**
   - The plugin will automatically create required database tables on activation
   - No manual database configuration needed

## ⚙️ Configuration

### 1. Security Token Setup

1. Navigate to **Support Ops → API Config**
2. Copy the generated security token
3. Use this token in your N8N workflows as `X-Audit-Token` header

### 2. System Message Configuration

1. Go to **Support Ops → System Message**
2. Customize the AI prompt for ticket auditing
3. Test the configuration before saving

### 3. Agent Setup

1. Navigate to **Support Ops → Agents**
2. Add support team members with their email addresses
3. Configure agent details (name, title, FluentSupport ID)

### 4. Shift Definitions

1. Go to **Support Ops → Settings**
2. Define shift types (Morning, Afternoon, Night, etc.)
3. Set start/end times and colors for each shift type

### 5. N8N Integration

Configure your N8N workflow to:
- Use the security token in HTTP headers
- Call endpoints: `/get-pending`, `/save-result`, `/get-ticket-with-responses`
- Handle webhook triggers for force audits

## 📡 API Endpoints

All endpoints are under: `/wp-json/ai-audit/v1/`

### Authentication
All endpoints (except agent endpoints) require the `X-Audit-Token` header.

### Audit Endpoints

#### `POST /save-result`
Save audit results from N8N after AI processing.

**Headers:**
```
X-Audit-Token: your_security_token
Content-Type: application/json
```

**Body:**
```json
{
  "ticket_id": 123,
  "status": "success",
  "score": 85,
  "audit_response": { ... }
}
```

#### `GET /get-pending`
Fetch pending tickets for batch processing.

**Query Parameters:**
- `limit` (optional, default: 10)

**Response:**
```json
[
  {"ticket_id": 123, "retry": false},
  {"ticket_id": 124, "retry": true}
]
```

#### `GET /get-ticket-with-responses`
Get ticket data with responses in N8N-compatible format.

**Query Parameters:**
- `ticket_id` (required)

**Response:**
```json
{
  "ticket": {
    "id": 123,
    "title": "...",
    "status": "...",
    "created_at": "...",
    "content": "..."
  },
  "responses": [...]
}
```

### Shift Endpoints

#### `GET /get-shift-context`
Get shift context for a specific ticket.

#### `POST /check-shift`
Check if an agent was on shift at a specific time.

#### `POST /check-shifts-batch`
Batch check multiple agent shift times.

### System Message Endpoints

#### `GET /get-system-message`
Retrieve the current AI system message.

#### `POST /save-system-message`
Update the AI system message.

#### `POST /test-system-message`
Test the system message with a sample ticket.

## 🎯 Usage

### Manual Audit Trigger

1. Go to **Support Ops → Audits**
2. Find the ticket you want to audit
3. Click **"Force Audit"** button
4. The system will:
   - Queue the ticket for AI processing
   - Show real-time status updates
   - Display results when complete

### Viewing Agent Performance

1. Navigate to **Support Ops → Agent Performance**
2. View overall team metrics
3. Click on an agent to see detailed analysis
4. Review scores, trends, and insights

### Scheduling Shifts

1. Go to **Support Ops → Calendar**
2. Select date range for bulk scheduling
3. Choose agents and shift types
4. Click **"Bulk Schedule"** to apply

## 📊 Database Schema

The plugin creates the following tables:

- `ais_audits` - Audit results and status
- `ais_agents` - Agent profiles
- `ais_agent_shifts` - Shift schedules
- `ais_shift_definitions` - Shift type definitions
- `ais_agent_evaluations` - Per-agent performance scores
- `ais_agent_contributions` - Agent contribution tracking
- `ais_problem_contexts` - Problem categorization
- `ais_topic_stats` - Topic statistics
- `ais_doc_central_meta` - Documentation metadata

## 🔒 Security

- Token-based API authentication
- WordPress nonce verification for admin actions
- Input sanitization and validation
- SQL injection protection via prepared statements
- Capability checks for admin functions

## 🛠️ Development

### Project Structure

```
ai-support-auditor/
├── includes/
│   ├── Admin/          # Admin interface
│   ├── API/            # REST API endpoints
│   ├── AJAX/           # AJAX handlers
│   ├── Database/       # Database management
│   └── Services/       # Business logic
├── assets/
│   └── images/         # Plugin assets
└── ai-support-auditor.php  # Main plugin file
```

### Code Style

- Object-oriented PHP
- WordPress coding standards
- PSR-4 autoloading
- Namespace: `SupportOps\`

## 📝 Changelog

### Version 10.0-OOP
- Complete OOP refactoring
- Modern SaaS design for admin pages
- Enhanced agent performance dashboard
- Improved API error handling
- Real-time status polling
- Logo and branding updates

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This plugin is proprietary software. All rights reserved.

## 🆘 Support

For issues, questions, or feature requests:
- Open an issue on GitHub
- Contact: reachkamrul@gmail.com

## 🙏 Acknowledgments

- Built for enterprise support operations
- Integrates with FluentSupport
- Powered by Google Gemini AI
- Automated via N8N workflows

---

**Made with ❤️ for Support Teams**


