
# 💬 VIRAX | ویراکس — Secure Real-Time Chat Platform

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![Security](https://img.shields.io/badge/Encryption-AES--256--CBC-success?style=for-the-badge)
![WebSocket](https://img.shields.io/badge/WebSocket-Real--Time-6366f1?style=for-the-badge)
![PWA](https://img.shields.io/badge/PWA-Supported-5A0FC8?style=for-the-badge)

**A modern, secure, and feature-rich real-time chat platform built with PHP and MySQL.**

**ویراکس** یک پلتفرم گفتگوی آنلاین، پیشرفته و امن است که با PHP و MySQL توسعه داده شده و امکانات گسترده‌ای برای ارتباط کاربران، مدیریت گفتگوها، ارسال فایل و پیام صوتی، اتاق‌ها و کانال‌ها و دستیار هوش مصنوعی ارائه می‌دهد.

---

## 🌟 About VIRAX | درباره ویراکس

**🇬🇧 English:**

VIRAX is a modern real-time chat platform designed with a strong focus on security, privacy, performance, and user experience.

Built using PHP, MySQL, HTML, CSS, and Vanilla JavaScript, VIRAX provides a complete communication environment without relying on heavy frameworks.

It includes encrypted messaging and media storage, passwordless authentication, private conversations, public and private channels, voice messages, AI-powered assistance, advanced administration tools, and much more.

**🇮🇷 فارسی:**

ویراکس یک پلتفرم چت‌روم مدرن و پیشرفته است که با تمرکز بر امنیت، حریم خصوصی، سرعت و تجربه کاربری توسعه داده شده است.

این پروژه با استفاده از PHP، MySQL و JavaScript خام طراحی شده و بدون نیاز به فریم‌ورک‌های سنگین، امکانات کاملی برای ارتباط آنلاین کاربران فراهم می‌کند.

از قابلیت‌های ویراکس می‌توان به رمزنگاری پیام‌ها و فایل‌ها، ورود بدون رمز عبور، گفتگوی خصوصی، کانال‌های عمومی و خصوصی، پیام صوتی، دستیار هوش مصنوعی و پنل مدیریت پیشرفته اشاره کرد.

---

## ✨ Key Features | امکانات ویراکس

### 🛡️ 1. Advanced Security | امنیت و احراز هویت

- **AES-256-CBC Encryption:** Encryption of messages and files before database storage.
- **Passwordless Authentication:** Login using a dedicated security code instead of a traditional password.
- **SMS Recovery:** Six-digit verification codes with expiration and resend limitations.
- **Device Trust:** Unique device tokens and individual or bulk session revocation.
- **Anti-Spam Protection:** Multi-layer rate limiting and automatic abuse prevention.
- **CSRF Protection:** Nonce validation and request security.
- **IP Blocking:** Temporary and permanent IP bans.
- **Captcha:** Custom captcha and suspicious activity detection.
- **Demo Mode:** Temporary testing accounts with automatic cleanup and limited access.

**قابلیت‌های امنیتی:**

- رمزنگاری پیام‌ها و فایل‌ها با الگوریتم AES-256-CBC
- ورود بدون نیاز به رمز عبور
- ورود با کد امنیتی اختصاصی
- بازیابی حساب از طریق پیامک
- کد تأیید ۶ رقمی با اعتبار ۵ دقیقه‌ای
- مدیریت و ابطال نشست‌های فعال
- شناسایی دستگاه‌های مورد اعتماد
- سیستم ضداسپم چندلایه
- محافظت در برابر CSRF
- کپچای اختصاصی
- مسدودسازی IP کاربران متخلف
- حساب آزمایشی با پاک‌سازی خودکار

### 💬 2. Messaging & Communication | سیستم گفتگو

- Public Chat Room
- Private Messaging (DM)
- Private Rooms with 8-digit invitation codes
- Public and Private Channels
- User Blocking and Unblocking
- Message Replies and Editing
- Message Deletion
- Message Pinning
- Emoji Reactions
- Live Message Search
- Typing Indicators
- Message Delivery and Read Receipts
- Unread Message Counters
- In-app Notifications

**امکانات گفتگو:**

- اتاق گفتگوی عمومی
- پیام خصوصی بین کاربران
- ساخت اتاق‌های خصوصی با کد دعوت ۸ رقمی
- کانال‌های عمومی و خصوصی
- مسدودسازی و رفع مسدودسازی کاربران
- پاسخ به پیام‌ها (Reply)
- ویرایش و حذف پیام‌ها
- سنجاق کردن پیام‌های مهم
- واکنش با ایموجی
- جستجوی زنده در پیام‌ها
- نمایش وضعیت تایپ کاربران
- تیک تحویل و خوانده شدن پیام
- شمارنده پیام‌های خوانده‌نشده
- اعلان‌های درون‌برنامه‌ای

### 🎤 3. Media & File Sharing | ارسال فایل و رسانه

- Voice Message Recording
- Audio Preview Before Sending
- Image and Video Uploads
- Chunked File Upload
- Upload Progress Indicator
- Secure Media Delivery
- Authenticated File Access
- HTTP Range Streaming
- Soft Media Deletion

**امکانات رسانه‌ای:**

- ضبط و ارسال پیام صوتی
- تایمر ضبط صدا
- پیش‌نمایش ویس قبل از ارسال
- ارسال تصاویر و ویدیوها
- آپلود تکه‌ای فایل‌ها (Chunked Upload)
- نمایش درصد پیشرفت آپلود
- حداکثر حجم پیش‌فرض ۵۰ مگابایت
- پخش امن فایل‌های رسانه‌ای
- جلوگیری از دسترسی مستقیم و غیرمجاز به فایل‌ها
- پشتیبانی از پخش و جابه‌جایی در فایل‌های صوتی و ویدیویی
- حذف نرم فایل‌ها بدون به‌هم‌ریختن تاریخچه گفتگو

### 🤖 4. AI Assistant | دستیار هوش مصنوعی

VIRAX includes an integrated AI assistant that works directly inside conversations.

- AI Responses in Public Rooms
- AI Responses in Private Messages
- Custom Trigger Word (Default: VIRAX)
- Reply-Based AI Interaction
- OpenAI-Compatible API Support
- Custom API Base URL
- Custom AI Models
- Configurable System Prompts
- Temperature and Token Controls
- Request Queue Management
- Automatic Retry Mechanism
- API Connection Testing

**امکانات دستیار هوشمند:**

- پاسخگویی هوشمند در اتاق‌های گفتگو
- امکان گفتگو با دستیار در پیام خصوصی
- فراخوانی دستیار با کلمه اختصاصی ویراکس
- پاسخگویی از طریق Reply
- پشتیبانی از APIهای سازگار با OpenAI
- تنظیم آدرس API و کلید دسترسی
- انتخاب مدل هوش مصنوعی
- تنظیم پرامپت اختصاصی
- مدیریت تعداد توکن و میزان خلاقیت پاسخ
- سیستم صف درخواست‌ها
- تلاش مجدد در صورت بروز خطا
- تست اتصال API از پنل مدیریت

### ⚙️ 5. Advanced Admin Dashboard | پنل مدیریت

VIRAX provides a powerful administration system with three different access levels.

**👑 Owner**
- Full system access
- Admin account management
- Permission management
- Complete control over platform settings

**🛡️ Full Admin**
- Platform configuration
- User management
- Backup management
- AI assistant configuration
- Branding management
- System locks and controls

**👁️ Limited Admin**
- User monitoring
- Session management
- Reports management
- Media monitoring
- Blocking management
- Restricted access to sensitive settings

**Additional Administration Features:**

- User Management
- Temporary and Permanent Bans
- IP Blocking
- Session Revocation
- Report Management
- Media Management
- Room and Channel Management
- Global Announcements
- Scheduled Notifications
- Registration and Login Controls
- Upload and Voice Restrictions
- Automatic Database Reset
- Website Branding Customization
- Social Media Link Management
- Database and Source Backups
- SMS Gateway Configuration
- WebSocket Configuration
- User Cache Management

**امکانات مدیریتی:**

- سه سطح دسترسی مالک، مدیر کامل و مدیر محدود
- مدیریت کاربران و نشست‌ها
- بن موقت و دائمی
- مسدودسازی IP
- مدیریت گزارش تخلفات
- مدیریت فایل‌های آپلودشده
- مدیریت اتاق‌ها و کانال‌ها
- ارسال پیام سراسری
- زمان‌بندی اعلان‌ها
- کنترل ثبت‌نام و ورود
- کنترل آپلود و ارسال ویس
- تنظیم سقف کاربران
- ریست خودکار دیتابیس
- شخصی‌سازی برندینگ و ظاهر سایت
- مدیریت لینک شبکه‌های اجتماعی
- تهیه نسخه پشتیبان از دیتابیس و سورس
- تنظیم درگاه پیامک
- مدیریت WebSocket و کش کاربران

### 🎨 6. Modern UI/UX | رابط کاربری مدرن

- Modern Dark Glassmorphism Design
- Responsive Layout
- Mobile-Friendly Interface
- Persian RTL Support
- Jalali Calendar
- Custom Fonts
- Three-Dimensional Icons
- Smooth Animations
- Context Menus
- Inline Message Replies
- User Profiles
- Welcome and Help Modals

**امکانات رابط کاربری:**

- طراحی مدرن با سبک Glassmorphism
- تم تاریک با گرادیان‌های زیبا
- کاملاً واکنش‌گرا برای موبایل و دسکتاپ
- پشتیبانی کامل از زبان فارسی و RTL
- تقویم شمسی و اعداد فارسی
- فونت اختصاصی
- آیکون‌های سه‌بعدی
- انیمیشن‌های روان
- منوهای اختصاصی پیام‌ها
- پروفایل اختصاصی کاربران
- راهنمای استفاده و پیام خوش‌آمدگویی

### 📱 7. Progressive Web App | پشتیبانی از PWA

- PWA Manifest
- Service Worker
- Installable on Mobile and Desktop
- Smart Asset Caching
- Home Screen Installation

ویراکس قابلیت نصب به‌عنوان یک اپلیکیشن تحت وب را دارد و با استفاده از Manifest و Service Worker امکان نصب روی صفحه اصلی موبایل و دسکتاپ را فراهم می‌کند.

---

## 🛠️ Technology Stack | تکنولوژی‌های استفاده‌شده

| Component | Technology |
|---|---|
| Backend | PHP 8+ |
| Database | MySQL |
| Database Interface | PDO |
| Frontend | HTML5, CSS3 |
| JavaScript | Vanilla JavaScript |
| Real-Time Communication | WebSocket + Long-Poll |
| Encryption | AES-256-CBC |
| Calendar | Jalali Calendar (jdf.php) |
| Application | Progressive Web App |
| AI Integration | OpenAI-Compatible API |

---

## ⚡ Real-Time Architecture | معماری ارتباط بلادرنگ

VIRAX uses a flexible real-time communication architecture.

- **WebSocket:** Optional real-time communication.
- **Long-Polling:** Automatic fallback when WebSocket is unavailable.
- **Incremental Polling:** Efficient synchronization of conversations.
- **Smart Fallback:** Automatic selection of the available communication method.
- **Rate Limiting:** Server-side request control and client-side load management.
- **Message Idempotency:** Prevents duplicate messages during network retries.

ویراکس برای ارتباط بلادرنگ از معماری ترکیبی استفاده می‌کند:

- ارتباط WebSocket به‌صورت اختیاری
- پشتیبانی از Long-Polling
- انتخاب خودکار روش ارتباطی
- همگام‌سازی افزایشی پیام‌ها
- مدیریت درخواست‌ها برای کنترل فشار روی سرور
- جلوگیری از ثبت پیام تکراری هنگام قطعی شبکه

---

## 🚀 Installation | نصب و راه‌اندازی

VIRAX includes a four-step installation wizard designed to simplify deployment.

### Installation Steps

1. Upload the project files to your PHP hosting or local server.
2. Open the website in your browser.
3. Follow the installation wizard.
4. Test the database connection.
5. Create the required database tables.
6. Set up your administrator account.
7. Configure your platform branding.
8. Complete the installation and start using VIRAX.

### مراحل نصب

1. فایل‌های ویراکس را روی هاست یا لوکال هاست قرار دهید.
2. آدرس سایت را در مرورگر باز کنید.
3. مراحل نصب اولیه را دنبال کنید.
4. اتصال دیتابیس را بررسی کنید.
5. جداول موردنیاز به‌صورت خودکار ایجاد می‌شوند.
6. حساب کاربری مدیر را بسازید.
7. تنظیمات اولیه برندینگ را انجام دهید.
8. نصب را تکمیل کنید و وارد ویراکس شوید.

**Installation Features:**
- Database Connection Testing
- Automatic Table Creation
- Administrator Account Setup
- Initial Branding Configuration
- Versioned Database Schema
- Automatic Database Migrations

---

## 📊 Default System Limits | محدودیت‌های پیش‌فرض

| Feature | Default Limit |
|---|---|
| Maximum Message Length | 320 Characters |
| Maximum File Size | 50 MB |
| Private Room Members | 8 Users |
| Private Room Invitation | 8-Digit Code |
| Anti-Spam | 10 Messages / 10 Seconds |
| Default Ban Duration | 120 Minutes |
| Recovery Code | 6 Digits |
| Recovery Code Expiration | 5 Minutes |
| SMS Resend Interval | 60 Seconds |

این محدودیت‌ها مقادیر پیش‌فرض سیستم هستند و برخی از آن‌ها از طریق تنظیمات و پنل مدیریت قابل تغییرند.

---

## 🗄️ Database & Configuration | دیتابیس و تنظیمات

VIRAX uses MySQL with PDO for database communication.

Key infrastructure features:

- Automatic Database Table Creation
- Versioned Database Schema
- Automatic Schema Upgrades
- Environment-First Configuration
- Dedicated Database Table Prefix
- Shared Database Compatibility
- Database Backup and Restore
- Automatic Database Maintenance

ویراکس از دیتابیس MySQL و رابط PDO استفاده می‌کند و امکاناتی مانند ساخت خودکار جداول، ارتقای نسخه دیتابیس، پیشوند اختصاصی جداول، پشتیبان‌گیری و سازگاری با دیتابیس‌های مشترک را ارائه می‌دهد.

---

## 🛡️ Security Notice | نکات امنیتی

Before deploying VIRAX to a public production environment:

- Configure a strong and private encryption key.
- Protect database credentials.
- Use HTTPS.
- Configure your SMS gateway securely.
- Restrict access to administrative accounts.
- Keep your PHP environment updated.
- Create regular backups.
- Review server permissions and file access rules.

**نکته مهم:** قبل از انتشار عمومی ویراکس، کلید رمزنگاری قدرتمند و اختصاصی تنظیم کنید، از اطلاعات دیتابیس محافظت کنید، HTTPS را فعال نگه دارید و دسترسی‌های مدیریتی و مجوز فایل‌ها را بررسی کنید.

---

## 👨‍💻 Developer & Credits | توسعه‌دهنده

**Developed by Pouya Fakham (پویا فخام)**

- **Project:** VIRAX | ویراکس
- **Developer:** Pouya Fakham
- **Telegram:** [@PooyaFakham](https://t.me/PooyaFakham)

VIRAX is developed with a focus on security, performance, privacy, and a modern communication experience.

اگر از ویراکس استفاده کردید یا در توسعه آن تغییراتی ایجاد کردید، لطفاً اعتبار توسعه‌دهنده اصلی پروژه را حفظ کنید. ❤️

---

<p align="center">
  <strong>💬 VIRAX — Secure Communication. Smarter Conversations.</strong>
  <br>
  <sub>© 2026 VIRAX. Developed by Pouya Fakham.</sub>
</p>
