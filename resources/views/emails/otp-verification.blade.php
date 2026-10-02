<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $otp }} là mã xác thực OTP của bạn - CSVSMART</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        /* Reset & Base Styles */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body {
            margin: 0 !important;
            padding: 0 !important;
            background-color: #F1F5F9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #0F172A;
            line-height: 1.6;
        }

        /* Responsive layout */
        @media screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
                margin: auto !important;
                border-radius: 0 !important;
            }
            .content-padding {
                padding: 24px 20px !important;
            }
            .otp-digit {
                font-size: 32px !important;
                letter-spacing: 6px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 30px 10px; background-color: #F1F5F9;">
    <!-- Hidden Preheader for Lock Screen Preview -->
    <div style="display: none; font-size: 1px; color: #F1F5F9; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;">
        Mã xác thực OTP của bạn là {{ $otp }}. Mã có hiệu lực trong {{ $expiryMinutes ?? 15 }} phút. Vui lòng không chia sẻ mã này.
    </div>

    <!-- Main Container -->
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center">
                <table class="email-container" role="presentation" border="0" cellpadding="0" cellspacing="0" width="580" style="max-width: 580px; background-color: #FFFFFF; border-radius: 18px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); border: 1px solid #E2E8F0;">
                    
                    <!-- BRAND HEADER -->
                    <tr>
                        <td style="background-color: #090D16; padding: 32px 36px; text-align: left; border-bottom: 1px solid #1E293B;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td valign="middle">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <!-- App Icon Box -->
                                                <td style="width: 44px; height: 44px; background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%); border: 1px solid rgba(255,255,255,0.15); border-radius: 10px; text-align: center; vertical-align: middle;">
                                                    <span style="font-size: 22px; line-height: 44px; display: inline-block;">🏢</span>
                                                </td>
                                                <td style="padding-left: 14px;">
                                                    <div style="font-size: 18px; font-weight: 800; letter-spacing: 0.5px; color: #FFFFFF; line-height: 1.2;">
                                                        SMART CASSAVAS
                                                        <span style="display: inline-block; width: 7px; height: 7px; border-radius: 50%; background-color: #10B981; margin-left: 5px; vertical-align: middle;"></span>
                                                    </div>
                                                    <div style="font-size: 11px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; color: #94A3B8; margin-top: 2px;">
                                                        BUILDING OS • HỆ THỐNG CĂN HỘ SỐ
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: 600; color: #38BDF8; background-color: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 20px;">
                                            🛡️ BẢO MẬT OTP
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- HERO / GREETING CONTENT -->
                    <tr>
                        <td class="content-padding" style="padding: 36px 36px 20px 36px;">
                            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #0284C7; margin-bottom: 6px;">
                                Cổng Xác Thực Người Dùng
                            </div>
                            <h2 style="margin: 0 0 14px 0; font-size: 22px; font-weight: 700; color: #0F172A; letter-spacing: -0.3px;">
                                Xin chào {{ $userName ?: 'Quý Cư Dân' }},
                            </h2>
                            <p style="margin: 0; font-size: 15px; color: #475569; line-height: 1.65;">
                                Hệ thống vừa tiếp nhận yêu cầu <strong style="color: #0F172A;">{{ $purpose }}</strong> của bạn trên nền tảng Quản Lý Tòa Nhà & Căn Hộ <strong>SMART CASSAVAS</strong>. Vui lòng nhập mã bảo mật bên dưới để hoàn tất:
                            </p>
                        </td>
                    </tr>

                    <!-- OTP DISPLAY BOX (HERO UX) -->
                    <tr>
                        <td style="padding: 0 36px 24px 36px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background: linear-gradient(180deg, #F8FAFC 0%, #F1F5F9 100%); border: 2px dashed #CBD5E1; border-radius: 14px; text-align: center;">
                                <tr>
                                    <td style="padding: 24px 20px;">
                                        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: #64748B; margin-bottom: 10px;">
                                            MÃ XÁC THỰC DÙNG MỘT LẦN (OTP)
                                        </div>
                                        
                                        <!-- OTP DIGITS -->
                                        <div class="otp-digit" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 38px; font-weight: 800; letter-spacing: 8px; color: #0F172A; text-shadow: 0 1px 2px rgba(0,0,0,0.05); padding: 6px 0;">
                                            {{ $formattedOtp ?? $otp }}
                                        </div>

                                        <div style="margin-top: 10px; font-size: 12px; color: #64748B; font-weight: 500;">
                                            <span>⏱️ Hiệu lực: <strong>{{ $expiryMinutes ?? 15 }} phút</strong></span>
                                            <span style="margin: 0 6px;">•</span>
                                            <span>🔒 Sử dụng duy nhất 1 lần</span>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- DETAILS METADATA (UX CLARITY) -->
                    <tr>
                        <td style="padding: 0 36px 24px 36px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border-radius: 10px; border: 1px solid #E2E8F0; font-size: 13px;">
                                <tr>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid #EDF2F7; color: #64748B; width: 35%;">
                                        Mục đích yêu cầu:
                                    </td>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid #EDF2F7; color: #0F172A; font-weight: 600;">
                                        {{ ucfirst($purpose) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; color: #64748B;">
                                        Thời gian gửi:
                                    </td>
                                    <td style="padding: 12px 16px; color: #0F172A; font-weight: 600;">
                                        {{ $requestedAt ?? now()->setTimezone('Asia/Ho_Chi_Minh')->format('H:i - d/m/Y') }} (GMT+7)
                                    </td>
                                </tr>
                                @if(!empty($ipAddress))
                                <tr>
                                    <td style="padding: 12px 16px; border-top: 1px solid #EDF2F7; color: #64748B;">
                                        Địa chỉ IP thiết bị:
                                    </td>
                                    <td style="padding: 12px 16px; border-top: 1px solid #EDF2F7; color: #0F172A; font-family: monospace; font-size: 12px;">
                                        {{ $ipAddress }}
                                    </td>
                                </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    <!-- SECURITY NOTICE BOX -->
                    <tr>
                        <td style="padding: 0 36px 28px 36px;">
                            <div style="background-color: #FFFBEB; border-left: 4px solid #F59E0B; border-radius: 8px; padding: 14px 16px;">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                    <tr>
                                        <td valign="top" style="width: 24px; font-size: 16px; line-height: 20px;">
                                            ⚠️
                                        </td>
                                        <td style="padding-left: 10px; font-size: 13px; line-height: 1.5; color: #92400E;">
                                            <strong>Lưu ý bảo mật quan trọng:</strong><br>
                                            Ban Quản Lý tòa nhà <strong>tuyệt đối không bao giờ</strong> gọi điện hoặc nhắn tin hỏi mã OTP này. Không chia sẻ mã xác thực cho bất kỳ ai để bảo vệ tài sản và căn hộ của bạn.
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>

                    <!-- CTA BUTTON -->
                    <tr>
                        <td style="padding: 0 36px 36px 36px; text-align: center;">
                            <a href="{{ $appUrl ?? 'http://localhost:8000' }}" target="_blank" style="display: inline-block; background-color: #0F172A; color: #FFFFFF; font-size: 14px; font-weight: 600; text-decoration: none; padding: 12px 28px; border-radius: 8px; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);">
                                Truy Cập Trang SMART CASSAVAS &rarr;
                            </a>
                            <div style="margin-top: 10px; font-size: 12px; color: #94A3B8;">
                                Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua thư này an toàn.
                            </div>
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td style="background-color: #F8FAFC; border-top: 1px solid #E2E8F0; padding: 24px 36px; text-align: center;">
                            <div style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px;">
                                BAN QUẢN LÝ TÒA NHÀ & VẬN HÀNH SMART CASSAVAS
                            </div>
                            <div style="font-size: 12px; color: #64748B; line-height: 1.6;">
                                Hotline Kỹ Thuật & Cư Dân: <strong style="color: #0F172A;">1900 8888</strong> • CSKH: <a href="mailto:cassvassmart@gmail.com" style="color: #0284C7; text-decoration: none;">cassvassmart@gmail.com</a><br>
                                Địa chỉ: Tòa nhà CSVSMART Tower, Khu Đô Thị Đổi Mới Sáng Tạo
                            </div>
                            <div style="margin-top: 12px; font-size: 11px; color: #94A3B8;">
                                &copy; {{ date('Y') }} SMART CASSAVAS Building OS. All rights reserved.
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
