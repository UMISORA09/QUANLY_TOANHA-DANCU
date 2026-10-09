import React from 'react';

interface CooldownBannerProps {
  isCooldownActive?: boolean;
  remainingSeconds?: number;
  customMessage?: string;
  className?: string;
}

export const CooldownBanner: React.FC<CooldownBannerProps> = () => {
  // Ẩn hoàn toàn thanh hiển thị thông báo tạm khóa theo yêu cầu giao diện người dùng
  return null;
};

