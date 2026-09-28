import React from 'react';
import {
  LayoutDashboard,
  Users,
  CreditCard,
  Sparkles,
  Wrench,
  AlertCircle,
  Shield,
  Car,
  Bell,
  BarChart3,
  FileText,
  Settings,
  HelpCircle,
  Building,
  Building2,
  Home,
  Receipt,
  User,
  MessageSquare,
  PhoneCall,
  UserCheck,
  Package,
  Key,
  ShieldAlert,
  ClipboardList,
  Contact,
  Layers,
  Database,
  Sliders,
  CalendarDays,
  Camera,
  Flame,
  CheckCircle2,
  Terminal,
  Bot,
  Cpu,
} from 'lucide-react';

export type UserRole = 'manager' | 'resident' | 'receptionist' | 'admin' | 'dev';

export interface NavigationItem {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }> | React.ElementType;
  badge?: string | number | null;
  badgeType?: 'danger' | 'warning' | 'info' | 'neutral' | 'success';
  href?: string;
  isNew?: boolean;
}

export interface NavigationRoleConfig {
  role: UserRole;
  roleName: string;
  portalSubtitle: string;
  sectionTitle: string;
  icon: React.ComponentType<{ className?: string }>;
  items: NavigationItem[];
  defaultPath: string;
  brandColor?: string;
}

export const NAVIGATION_CONFIGS: Record<UserRole, NavigationRoleConfig> = {
  // ================= 1. BAN QUẢN LÝ (BUILDING MANAGEMENT) =================
  manager: {
    role: 'manager',
    roleName: 'Ban Quản Lý (Manager)',
    portalSubtitle: 'BUILDING MANAGEMENT',
    sectionTitle: 'KHÔNG GIAN VẬN HÀNH TÒA NHÀ',
    icon: Building2,
    defaultPath: '/quan-ly',
    items: [
      { id: 'overview', label: 'Bàn làm việc Vận hành', icon: LayoutDashboard },
      { id: 'residents', label: 'Cư dân & Căn hộ', icon: Users, badge: '1,248' },
      { id: 'billing', label: 'Thu phí & Hóa đơn', icon: CreditCard, badge: '86', badgeType: 'warning' },
      { id: 'amenities', label: 'Tiện ích tòa nhà', icon: Sparkles },
      { id: 'maintenance', label: 'Kỹ thuật & Bảo trì', icon: Wrench, badge: '4' },
      { id: 'tickets', label: 'Phản ánh & Sự cố', icon: AlertCircle, badge: '12 mới', badgeType: 'danger' },
      { id: 'security', label: 'An ninh & Ra vào', icon: Shield },
      { id: 'parking', label: 'Phương tiện & Bãi đỗ', icon: Car },
      { id: 'notices', label: 'Thông báo & Tin tức', icon: Bell },
      { id: 'reports', label: 'Báo cáo & Thống kê', icon: BarChart3 },
      { id: 'contracts', label: 'Hợp đồng & Đối tác', icon: FileText },
      { id: 'cctv', label: 'Giám sát CCTV & IoT', icon: Camera },
      { id: 'fire_safety', label: 'PCCC & Cảnh báo', icon: Flame },
      { id: 'settings', label: 'Cài đặt vận hành', icon: Settings },
    ],
  },

  // ================= 2. CƯ DÂN (RESIDENT PORTAL) =================
  resident: {
    role: 'resident',
    roleName: 'Cư Dân Cassavas',
    portalSubtitle: 'RESIDENT PORTAL',
    sectionTitle: 'KHÔNG GIAN CƯ DÂN',
    icon: Home,
    defaultPath: '/cu-dan',
    items: [
      { id: 'overview', label: 'Tổng quan Cư dân', icon: Home },
      { id: 'billing', label: 'Hóa đơn của tôi', icon: Receipt, badge: '1', badgeType: 'warning' },
      { id: 'tickets', label: 'Báo cáo sự cố', icon: AlertCircle, badge: '2', badgeType: 'danger' },
      { id: 'amenities', label: 'Đặt tiện ích', icon: Sparkles },
      { id: 'visitors', label: 'Khai báo khách', icon: Users },
      { id: 'profile', label: 'Thông tin cá nhân', icon: User },
      { id: 'community', label: 'Cộng đồng cư dân', icon: MessageSquare },
      { id: 'feedback', label: 'Góp ý & Khảo sát', icon: FileText },
      { id: 'contact_pet', label: 'Liên hệ & Thú cưng', icon: PhoneCall },
    ],
  },

  // ================= 3. LỄ TÂN & BẢO VỆ (RECEPTION & SECURITY) =================
  receptionist: {
    role: 'receptionist',
    roleName: 'Lễ Tân & Bảo VỆ',
    portalSubtitle: 'LỄ TÂN & AN NINH',
    sectionTitle: 'KHÔNG GIAN LỄ TÂN & AN NINH',
    icon: Building2,
    defaultPath: '/le-tan',
    items: [
      { id: 'reception', label: 'Bàn trực Lễ tân', icon: LayoutDashboard },
      { id: 'visitors', label: 'Khách viếng thăm', icon: UserCheck, badge: '12 đang ở', badgeType: 'info' },
      { id: 'parcels', label: 'Giao nhận bưu phẩm', icon: Package, badge: '24 chờ lấy', badgeType: 'warning' },
      { id: 'keys', label: 'Chìa khóa & Thẻ từ', icon: Key },
      { id: 'parking', label: 'An ninh bãi xe & Ra vào', icon: Car },
      { id: 'incidents', label: 'Sự cố khẩn cấp', icon: ShieldAlert, badge: '1 mới', badgeType: 'danger' },
      { id: 'handover', label: 'Sổ giao ca trực', icon: ClipboardList },
      { id: 'directory', label: 'Danh bạ nội bộ', icon: Contact },
    ],
  },

  // ================= 4. QUẢN TRỊ VIÊN & KỸ THUẬT (ADMIN & DEV PORTAL) =================
  admin: {
    role: 'admin',
    roleName: 'Quản Trị Viên & Kỹ Thuật (Admin / Dev)',
    portalSubtitle: 'SYSTEM ADMINISTRATOR & DEV CONSOLE',
    sectionTitle: 'TRUNG TÂM QUẢN TRỊ & KỸ THUẬT',
    icon: Shield,
    defaultPath: '/admin',
    items: [
      { id: 'overview', label: 'Bàn làm việc Quản trị & Dev', icon: LayoutDashboard },
      { id: 'roles', label: 'Phân quyền tài khoản (RBAC)', icon: Shield, badge: 'Đặc quyền', badgeType: 'warning' },
      { id: 'amenities', label: 'Tiện ích & Cấu hình Slot', icon: Sparkles },
      { id: 'ai_triage', label: 'AI Chatbot & Triage Ticket', icon: Bot, badge: 'AI Smart', badgeType: 'info' },
      { id: 'api_iot', label: 'API, Webhook & IoT', icon: Cpu, badge: '4 active', badgeType: 'success' },
      { id: 'audit_flags', label: 'Feature Flags & Audit Logs', icon: Sliders, badge: '5 flags' },
      { id: 'cicd', label: 'CI/CD & DevOps Pipeline', icon: Terminal, badge: 'Live', badgeType: 'success', isNew: true },
      { id: 'manager_portal', label: 'Chuyển sang Cổng Ban Quản Lý', icon: Building2 },
    ],
  },

  // ================= 5. DEVELOPER (HỢP NHẤT CHUNG VỚI ADMIN) =================
  dev: {
    role: 'admin',
    roleName: 'Quản Trị Viên & Kỹ Thuật (Admin / Dev)',
    portalSubtitle: 'SYSTEM ADMINISTRATOR & DEV CONSOLE',
    sectionTitle: 'TRUNG TÂM QUẢN TRỊ & KỸ THUẬT',
    icon: Shield,
    defaultPath: '/admin',
    items: [
      { id: 'overview', label: 'Bàn làm việc Quản trị & Dev', icon: LayoutDashboard },
      { id: 'roles', label: 'Phân quyền tài khoản (RBAC)', icon: Shield, badge: 'Đặc quyền', badgeType: 'warning' },
      { id: 'amenities', label: 'Tiện ích & Cấu hình Slot', icon: Sparkles },
      { id: 'ai_triage', label: 'AI Chatbot & Triage Ticket', icon: Bot, badge: 'AI Smart', badgeType: 'info' },
      { id: 'api_iot', label: 'API, Webhook & IoT', icon: Cpu, badge: '4 active', badgeType: 'success' },
      { id: 'audit_flags', label: 'Feature Flags & Audit Logs', icon: Sliders, badge: '5 flags' },
      { id: 'cicd', label: 'CI/CD & DevOps Pipeline', icon: Terminal, badge: 'Live', badgeType: 'success', isNew: true },
      { id: 'manager_portal', label: 'Chuyển sang Cổng Ban Quản Lý', icon: Building2 },
    ],
  },
};

export const QUICK_PORTALS = [
  { role: 'admin', label: 'Cổng Quản Trị & Kỹ Thuật (Admin / Dev)', path: '/admin', icon: Shield, color: 'text-amber-500' },
  { role: 'manager', label: 'Cổng Ban Quản Lý (Manager)', path: '/quan-ly', icon: Building2, color: 'text-sky-500' },
  { role: 'receptionist', label: 'Cổng Lễ Tân & An Ninh', path: '/le-tan', icon: Building, color: 'text-indigo-500' },
  { role: 'resident', label: 'Cổng Dịch Vụ Cư Dân', path: '/cu-dan', icon: Home, color: 'text-emerald-500' },
];
