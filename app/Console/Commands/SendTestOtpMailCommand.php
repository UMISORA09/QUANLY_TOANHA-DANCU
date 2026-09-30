<?php

namespace App\Console\Commands;

use App\Mail\OtpVerificationMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestOtpMailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test-otp {email? : Địa chỉ email nhận thử nghiệm}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gửi email thử nghiệm chứa mã OTP đến địa chỉ email chỉ định để kiểm tra cấu hình SMTP';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        if (! $email) {
            $email = $this->ask('Vui lòng nhập địa chỉ email nhận mã OTP thử nghiệm');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Địa chỉ email '{$email}' không hợp lệ.");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $host = config('mail.mailers.smtp.host');
        $port = config('mail.mailers.smtp.port');
        $username = config('mail.mailers.smtp.username');
        $fromAddress = config('mail.from.address');

        $this->info('--------------------------------------------------');
        $this->info('Đang kiểm tra cấu hình gửi mail...');
        $this->line("• Mail Driver   : {$mailer}");
        $this->line("• SMTP Host     : {$host}");
        $this->line("• SMTP Port     : {$port}");
        $this->line("• SMTP Username : {$username}");
        $this->line("• From Address  : {$fromAddress}");
        $this->line("• Người nhận    : {$email}");
        $this->info('--------------------------------------------------');

        $otp = sprintf('%06d', random_int(100000, 999999));

        $this->line("Đang gửi email chứa mã OTP [{$otp}]...");

        try {
            Mail::to($email)->send(new OtpVerificationMail(
                otp: $otp,
                purpose: 'kiểm tra cấu hình SMTP',
                userName: 'Admin / Kiểm thử viên',
                expiryMinutes: 15,
                ipAddress: '127.0.0.1 (Kiểm thử viên)'
            ));

            $this->newLine();
            $this->info(' GỬI EMAIL THÀNH CÔNG!');
            $this->line("Mã OTP đã được gửi tới [{$email}]. Vui lòng kiểm tra hộp thư đến (Inbox) hoặc thư rác (Spam).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error(' GỬI EMAIL THẤT BẠI: '.$e->getMessage());
            $this->newLine();
            $this->warn('Gợi ý khắc phục:');
            $this->line("1. Nếu dùng Gmail: Bạn cần dùng 'Mật khẩu ứng dụng' (App Password 16 ký tự), không phải mật khẩu đăng nhập Google thông thường.");
            $this->line('2. Kiểm tra thông số .env:');
            $this->line('   MAIL_MAILER=smtp');
            $this->line('   MAIL_HOST=smtp.gmail.com');
            $this->line('   MAIL_PORT=587');
            $this->line('   MAIL_USERNAME=your-email@gmail.com');
            $this->line('   MAIL_PASSWORD=your-app-password');
            $this->line('   MAIL_ENCRYPTION=tls');
            $this->line('   MAIL_FROM_ADDRESS=your-email@gmail.com');
            $this->line('3. Sau khi sửa file .env, chạy: php artisan config:clear');

            return self::FAILURE;
        }
    }
}
