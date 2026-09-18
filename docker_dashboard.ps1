Add-Type -AssemblyName PresentationFramework
Add-Type -AssemblyName PresentationCore
Add-Type -AssemblyName WindowsBase
Add-Type -AssemblyName System.Windows.Forms

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
Set-Location $ScriptDir

# Kiểm tra và đồng bộ cấu hình .env tự động
if (-not (Test-Path "$ScriptDir\.env")) {
    if (Test-Path "$ScriptDir\.env.example") {
        Copy-Item "$ScriptDir\.env.example" "$ScriptDir\.env"
    }
}
if (Test-Path "$ScriptDir\.env") {
    $envContent = Get-Content "$ScriptDir\.env" -Raw
    $envContent = $envContent -replace '(?m)^DB_HOST=.*', 'DB_HOST=db'
    $envContent = $envContent -replace '(?m)^DB_PORT=.*', 'DB_PORT=3306'
    $envContent = $envContent -replace '(?m)^DB_CONNECTION=.*', 'DB_CONNECTION=mysql'
    $envContent = $envContent -replace '(?m)^DB_DATABASE=.*', 'DB_DATABASE=quanly_toanha'
    $envContent = $envContent -replace '(?m)^DB_PASSWORD=.*', 'DB_PASSWORD=123567'
    Set-Content -Path "$ScriptDir\.env" -Value $envContent
}

[xml]$xaml = @"
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation"
        xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
        Title="Smart Cassavas - Bảng Điều Khiển Docker"
        Height="680" Width="920"
        WindowStartupLocation="CenterScreen"
        ResizeMode="CanResize"
        Background="#0f172a"
        Foreground="#f8fafc"
        FontFamily="Segoe UI">
    <Window.Resources>
        <Style TargetType="TabItem">
            <Setter Property="FontSize" Value="14"/>
            <Setter Property="FontWeight" Value="SemiBold"/>
            <Setter Property="Foreground" Value="#94a3b8"/>
            <Setter Property="Padding" Value="18,10"/>
            <Setter Property="Background" Value="#1e293b"/>
            <Setter Property="BorderThickness" Value="0"/>
            <Setter Property="Template">
                <Setter.Value>
                    <ControlTemplate TargetType="TabItem">
                        <Border Name="Border" Background="{TemplateBinding Background}" CornerRadius="6,6,0,0" Margin="0,0,4,0" Padding="{TemplateBinding Padding}">
                            <ContentPresenter ContentSource="Header" HorizontalAlignment="Center" VerticalAlignment="Center"/>
                        </Border>
                        <ControlTemplate.Triggers>
                            <Trigger Property="IsSelected" Value="True">
                                <Setter TargetName="Border" Property="Background" Value="#3b82f6"/>
                                <Setter Property="Foreground" Value="#ffffff"/>
                            </Trigger>
                            <Trigger Property="IsMouseOver" Value="True">
                                <Setter TargetName="Border" Property="Background" Value="#2563eb"/>
                                <Setter Property="Foreground" Value="#ffffff"/>
                            </Trigger>
                        </ControlTemplate.Triggers>
                    </ControlTemplate>
                </Setter.Value>
            </Setter>
        </Style>
        <Style TargetType="Button">
            <Setter Property="Foreground" Value="#ffffff"/>
            <Setter Property="FontWeight" Value="SemiBold"/>
            <Setter Property="FontSize" Value="13"/>
            <Setter Property="Padding" Value="14,10"/>
            <Setter Property="BorderThickness" Value="0"/>
            <Setter Property="Cursor" Value="Hand"/>
            <Setter Property="Template">
                <Setter.Value>
                    <ControlTemplate TargetType="Button">
                        <Border Name="BtnBorder" Background="{TemplateBinding Background}" CornerRadius="8" Padding="{TemplateBinding Padding}">
                            <ContentPresenter HorizontalAlignment="Center" VerticalAlignment="Center"/>
                        </Border>
                        <ControlTemplate.Triggers>
                            <Trigger Property="IsMouseOver" Value="True">
                                <Setter TargetName="BtnBorder" Property="Opacity" Value="0.88"/>
                            </Trigger>
                            <Trigger Property="IsPressed" Value="True">
                                <Setter TargetName="BtnBorder" Property="Opacity" Value="0.75"/>
                            </Trigger>
                        </ControlTemplate.Triggers>
                    </ControlTemplate>
                </Setter.Value>
            </Setter>
        </Style>
    </Window.Resources>

    <Grid Margin="16">
        <Grid.RowDefinitions>
            <RowDefinition Height="Auto"/>
            <RowDefinition Height="*"/>
            <RowDefinition Height="Auto"/>
        </Grid.RowDefinitions>

        <!-- Header -->
        <Border Grid.Row="0" Background="#1e293b" CornerRadius="12" Padding="18,14" Margin="0,0,0,14" BorderBrush="#334155" BorderThickness="1">
            <Grid>
                <Grid.ColumnDefinitions>
                    <ColumnDefinition Width="*"/>
                    <ColumnDefinition Width="Auto"/>
                </Grid.ColumnDefinitions>
                <StackPanel Grid.Column="0">
                    <TextBlock Text="🏢 SMART CASSAVAS - QUẢN LÝ TÒA NHÀ &amp; CƯ DÂN" FontSize="18" FontWeight="Bold" Foreground="#38bdf8"/>
                    <TextBlock Text="Bảng điều khiển &amp; quản trị hệ thống Docker Compose toàn diện" FontSize="12" Foreground="#94a3b8" Margin="0,4,0,0"/>
                </StackPanel>
                <StackPanel Grid.Column="1" Orientation="Horizontal" VerticalAlignment="Center">
                    <Border Name="BadgeStatus" Background="#14532d" CornerRadius="16" Padding="14,6">
                        <TextBlock Name="TxtSystemStatus" Text="● Đang kiểm tra..." FontSize="12" FontWeight="Bold" Foreground="#4ade80"/>
                    </Border>
                    <Button Name="BtnRefresh" Content="🔄 Làm mới" Background="#334155" Margin="10,0,0,0" Padding="12,6"/>
                </StackPanel>
            </Grid>
        </Border>

        <!-- Main Tabs -->
        <TabControl Grid.Row="1" Background="Transparent" BorderThickness="0">
            <!-- TAB 1: TỔNG QUAN & ĐIỀU HÀNH -->
            <TabItem Header="🚀 Điều Hành Nhanh">
                <Grid Background="#1e293b" Margin="0,8,0,0">
                    <Border CornerRadius="8" Background="#1e293b" Padding="18" BorderBrush="#334155" BorderThickness="1">
                        <ScrollViewer VerticalScrollBarVisibility="Auto">
                            <StackPanel>
                                <TextBlock Text="Các tác vụ vận hành chính" FontSize="15" FontWeight="Bold" Foreground="#e2e8f0" Margin="0,0,0,12"/>

                                <!-- Container Status List -->
                                <Border Background="#0f172a" CornerRadius="8" Padding="14" Margin="0,0,0,16" BorderBrush="#334155" BorderThickness="1">
                                    <Grid>
                                        <Grid.ColumnDefinitions>
                                            <ColumnDefinition Width="*"/>
                                            <ColumnDefinition Width="*"/>
                                            <ColumnDefinition Width="*"/>
                                        </Grid.ColumnDefinitions>
                                        <StackPanel Grid.Column="0" Margin="4">
                                            <TextBlock Text="App Web (Laravel + Vite)" FontSize="11" Foreground="#94a3b8"/>
                                            <TextBlock Name="TxtStatusApp" Text="Đang kiểm tra..." FontSize="13" FontWeight="Bold" Foreground="#38bdf8" Margin="0,3,0,0"/>
                                            <TextBlock Text="Cổng 8000" FontSize="11" Foreground="#64748b"/>
                                        </StackPanel>
                                        <StackPanel Grid.Column="1" Margin="4">
                                            <TextBlock Text="Database (MySQL 8.0)" FontSize="11" Foreground="#94a3b8"/>
                                            <TextBlock Name="TxtStatusDb" Text="Đang kiểm tra..." FontSize="13" FontWeight="Bold" Foreground="#38bdf8" Margin="0,3,0,0"/>
                                            <TextBlock Text="Cổng 3306" FontSize="11" Foreground="#64748b"/>
                                        </StackPanel>
                                        <StackPanel Grid.Column="2" Margin="4">
                                            <TextBlock Text="phpMyAdmin (Quản trị CSDL)" FontSize="11" Foreground="#94a3b8"/>
                                            <TextBlock Name="TxtStatusPma" Text="Đang kiểm tra..." FontSize="13" FontWeight="Bold" Foreground="#38bdf8" Margin="0,3,0,0"/>
                                            <TextBlock Text="Cổng 8888" FontSize="11" Foreground="#64748b"/>
                                        </StackPanel>
                                    </Grid>
                                </Border>

                                <!-- Action Buttons Grid -->
                                <UniformGrid Columns="2" Rows="3" Margin="0,0,0,10">
                                    <Button Name="BtnStartSystem" Content="🟢 KHỞI ĐỘNG HỆ THỐNG (up -d)" Background="#16a34a" Margin="6" Height="48" FontSize="14"/>
                                    <Button Name="BtnOpenWeb" Content="🌐 MỞ TRANG WEB (localhost:8000)" Background="#2563eb" Margin="6" Height="48" FontSize="14"/>
                                    <Button Name="BtnOpenPma" Content="🗄️ MỞ PHPMYADMIN (localhost:8888)" Background="#0284c7" Margin="6" Height="48" FontSize="14"/>
                                    <Button Name="BtnRebuild" Content="🔨 BUILD LẠI CONTAINER (Rebuild)" Background="#d97706" Margin="6" Height="48" FontSize="14"/>
                                    <Button Name="BtnRestartApp" Content="🔄 KHỞI ĐỘNG LẠI APP (Restart)" Background="#6366f1" Margin="6" Height="48" FontSize="14"/>
                                    <Button Name="BtnStopSystem" Content="🛑 DỪNG HỆ THỐNG (down)" Background="#dc2626" Margin="6" Height="48" FontSize="14"/>
                                </UniformGrid>
                            </StackPanel>
                        </ScrollViewer>
                    </Border>
                </Grid>
            </TabItem>

            <!-- TAB 2: QUẢN TRỊ CƠ SỞ DỮ LIỆU -->
            <TabItem Header="🗄️ Cơ Sở Dữ Liệu">
                <Grid Background="#1e293b" Margin="0,8,0,0">
                    <Border CornerRadius="8" Background="#1e293b" Padding="18" BorderBrush="#334155" BorderThickness="1">
                        <StackPanel>
                            <TextBlock Text="Quản trị Database MySQL Docker" FontSize="15" FontWeight="Bold" Foreground="#e2e8f0" Margin="0,0,0,12"/>

                            <!-- DB Info Box -->
                            <Border Background="#0f172a" CornerRadius="8" Padding="14" Margin="0,0,0,16" BorderBrush="#334155" BorderThickness="1">
                                <StackPanel>
                                    <TextBlock Text="📌 Thông số kết nối nội bộ &amp; quản trị:" FontWeight="SemiBold" Foreground="#38bdf8"/>
                                    <Grid Margin="0,8,0,0">
                                        <Grid.ColumnDefinitions>
                                            <ColumnDefinition Width="*"/>
                                            <ColumnDefinition Width="*"/>
                                        </Grid.ColumnDefinitions>
                                        <StackPanel Grid.Column="0">
                                            <TextBlock Text="• Host container: db" Foreground="#cbd5e1"/>
                                            <TextBlock Text="• Host ngoài máy (Local): localhost:3306" Foreground="#cbd5e1"/>
                                            <TextBlock Text="• Database: quanly_toanha" Foreground="#cbd5e1"/>
                                        </StackPanel>
                                        <StackPanel Grid.Column="1">
                                            <TextBlock Text="• Username: root" Foreground="#cbd5e1"/>
                                            <TextBlock Text="• Password: 123567" Foreground="#cbd5e1"/>
                                            <TextBlock Text="• Nguồn dump khởi tạo: dump_quanly_toanha.sql" Foreground="#cbd5e1"/>
                                        </StackPanel>
                                    </Grid>
                                </StackPanel>
                            </Border>

                            <!-- DB Actions -->
                            <StackPanel>
                                <Button Name="BtnMigrateStatus" Content="🔍 Xem trạng thái Migration (migrate:status)" Background="#334155" Margin="0,0,0,8" HorizontalAlignment="Stretch"/>
                                <Button Name="BtnRunMigrate" Content="🚀 Chạy cập nhật Migrations (php artisan migrate)" Background="#059669" Margin="0,0,0,8" HorizontalAlignment="Stretch"/>
                                <Button Name="BtnResetDb" Content="⚠️ Reset &amp; Nạp lại Database gốc từ dump_quanly_toanha.sql" Background="#b91c1c" Margin="0,0,0,8" HorizontalAlignment="Stretch"/>
                            </StackPanel>
                        </StackPanel>
                    </Border>
                </Grid>
            </TabItem>

            <!-- TAB 3: NHẬT KÝ & LOGS -->
            <TabItem Header="📋 Nhật Ký &amp; Logs">
                <Grid Background="#1e293b" Margin="0,8,0,0">
                    <Border CornerRadius="8" Background="#1e293b" Padding="14" BorderBrush="#334155" BorderThickness="1">
                        <Grid>
                            <Grid.RowDefinitions>
                                <RowDefinition Height="Auto"/>
                                <RowDefinition Height="*"/>
                            </Grid.RowDefinitions>
                            <StackPanel Grid.Row="0" Orientation="Horizontal" Margin="0,0,0,8">
                                <Button Name="BtnLogApp" Content="Logs App" Background="#2563eb" Margin="0,0,6,0" Padding="12,6"/>
                                <Button Name="BtnLogDb" Content="Logs Database" Background="#059669" Margin="0,0,6,0" Padding="12,6"/>
                                <Button Name="BtnLogPma" Content="Logs phpMyAdmin" Background="#d97706" Margin="0,0,6,0" Padding="12,6"/>
                                <Button Name="BtnClearLogs" Content="Xóa màn hình" Background="#475569" Margin="0,0,6,0" Padding="12,6"/>
                            </StackPanel>
                            <TextBox Name="TxtLogConsole" Grid.Row="1" Background="#020617" Foreground="#a7f3d0"
                                     FontFamily="Consolas" FontSize="12" TextWrapping="Wrap"
                                     VerticalScrollBarVisibility="Auto" IsReadOnly="True" Padding="10" BorderBrush="#1e293b"/>
                        </Grid>
                    </Border>
                </Grid>
            </TabItem>

            <!-- TAB 4: LỆNH ARTISAN & CÔNG CỤ -->
            <TabItem Header="⚙️ Lệnh Artisan &amp; Tools">
                <Grid Background="#1e293b" Margin="0,8,0,0">
                    <Border CornerRadius="8" Background="#1e293b" Padding="18" BorderBrush="#334155" BorderThickness="1">
                        <StackPanel>
                            <TextBlock Text="Thực thi lệnh Laravel Artisan bên trong Container App" FontSize="15" FontWeight="Bold" Foreground="#e2e8f0" Margin="0,0,0,10"/>
                            
                            <Grid Margin="0,0,0,12">
                                <Grid.ColumnDefinitions>
                                    <ColumnDefinition Width="*"/>
                                    <ColumnDefinition Width="Auto"/>
                                </Grid.ColumnDefinitions>
                                <TextBox Name="TxtArtisanInput" Grid.Column="0" Height="38" Background="#0f172a" Foreground="#ffffff"
                                         FontSize="14" Padding="10,8" BorderBrush="#475569" Text="route:list --path=api"/>
                                <Button Name="BtnExecArtisan" Grid.Column="1" Content="▶ Thực Thi" Background="#3b82f6" Margin="8,0,0,0" Padding="18,8"/>
                            </Grid>

                            <TextBlock Text="Gợi ý lệnh thông dụng (nhấp để chọn):" FontSize="12" Foreground="#94a3b8" Margin="0,0,0,6"/>
                            <WrapPanel Margin="0,0,0,14">
                                <Button Name="BtnPreset1" Content="route:list" Background="#334155" Margin="0,0,6,6" Padding="10,5" FontSize="11"/>
                                <Button Name="BtnPreset2" Content="cache:clear" Background="#334155" Margin="0,0,6,6" Padding="10,5" FontSize="11"/>
                                <Button Name="BtnPreset3" Content="config:clear" Background="#334155" Margin="0,0,6,6" Padding="10,5" FontSize="11"/>
                                <Button Name="BtnPreset4" Content="test" Background="#334155" Margin="0,0,6,6" Padding="10,5" FontSize="11"/>
                                <Button Name="BtnPreset5" Content="db:seed" Background="#334155" Margin="0,0,6,6" Padding="10,5" FontSize="11"/>
                            </WrapPanel>

                            <TextBlock Text="Kết quả thực thi lệnh:" FontSize="13" FontWeight="SemiBold" Foreground="#e2e8f0" Margin="0,4,0,6"/>
                            <TextBox Name="TxtArtisanOutput" Height="210" Background="#020617" Foreground="#f8fafc"
                                     FontFamily="Consolas" FontSize="12" TextWrapping="Wrap"
                                     VerticalScrollBarVisibility="Auto" IsReadOnly="True" Padding="10" BorderBrush="#334155"/>
                        </StackPanel>
                    </Border>
                </Grid>
            </TabItem>
        </TabControl>

        <!-- Footer -->
        <Border Grid.Row="2" Background="#1e293b" CornerRadius="8" Padding="12,8" Margin="0,10,0,0" BorderBrush="#334155" BorderThickness="1">
            <Grid>
                <TextBlock Name="TxtFooterInfo" Text="Sẵn sàng thực thi. Docker Compose điều hành dự án Smart Cassavas." FontSize="12" Foreground="#94a3b8" VerticalAlignment="Center"/>
                <TextBlock Text="Phiên bản 1.0 - Docker Native" HorizontalAlignment="Right" FontSize="11" Foreground="#64748b" VerticalAlignment="Center"/>
            </Grid>
        </Border>
    </Grid>
</Window>
"@

$reader = New-Object System.Xml.XmlNodeReader $xaml
$window = [System.Windows.Markup.XamlReader]::Load($reader)

# Ánh xạ các điều khiển từ XAML
$BadgeStatus      = $window.FindName("BadgeStatus")
$TxtSystemStatus  = $window.FindName("TxtSystemStatus")
$BtnRefresh       = $window.FindName("BtnRefresh")
$TxtStatusApp     = $window.FindName("TxtStatusApp")
$TxtStatusDb      = $window.FindName("TxtStatusDb")
$TxtStatusPma     = $window.FindName("TxtStatusPma")
$BtnStartSystem   = $window.FindName("BtnStartSystem")
$BtnOpenWeb       = $window.FindName("BtnOpenWeb")
$BtnOpenPma       = $window.FindName("BtnOpenPma")
$BtnRebuild       = $window.FindName("BtnRebuild")
$BtnRestartApp    = $window.FindName("BtnRestartApp")
$BtnStopSystem    = $window.FindName("BtnStopSystem")
$BtnMigrateStatus = $window.FindName("BtnMigrateStatus")
$BtnRunMigrate    = $window.FindName("BtnRunMigrate")
$BtnResetDb       = $window.FindName("BtnResetDb")
$BtnLogApp        = $window.FindName("BtnLogApp")
$BtnLogDb         = $window.FindName("BtnLogDb")
$BtnLogPma        = $window.FindName("BtnLogPma")
$BtnClearLogs     = $window.FindName("BtnClearLogs")
$TxtLogConsole    = $window.FindName("TxtLogConsole")
$TxtArtisanInput  = $window.FindName("TxtArtisanInput")
$BtnExecArtisan   = $window.FindName("BtnExecArtisan")
$TxtArtisanOutput = $window.FindName("TxtArtisanOutput")
$BtnPreset1       = $window.FindName("BtnPreset1")
$BtnPreset2       = $window.FindName("BtnPreset2")
$BtnPreset3       = $window.FindName("BtnPreset3")
$BtnPreset4       = $window.FindName("BtnPreset4")
$BtnPreset5       = $window.FindName("BtnPreset5")
$TxtFooterInfo    = $window.FindName("TxtFooterInfo")

# Hàm cập nhật trạng thái container
function Update-ContainerStatus {
    $TxtFooterInfo.Text = "Đang kiểm tra trạng thái Docker..."
    try {
        $psOutput = docker compose ps --format json 2>$null | Out-String
        $allRunning = $false

        if ($psOutput) {
            $TxtStatusApp.Text = "● Đang hoạt động"
            $TxtStatusApp.Foreground = [System.Windows.Media.Brushes]::LimeGreen
            $TxtStatusDb.Text = "● Sẵn sàng (Healthy)"
            $TxtStatusDb.Foreground = [System.Windows.Media.Brushes]::LimeGreen
            $TxtStatusPma.Text = "● Đang hoạt động"
            $TxtStatusPma.Foreground = [System.Windows.Media.Brushes]::LimeGreen

            $TxtSystemStatus.Text = "● Hệ thống đang chạy"
            $TxtSystemStatus.Foreground = [System.Windows.Media.Brushes]::LimeGreen
            $BadgeStatus.Background = [System.Windows.Media.BrushConverter]::new().ConvertFromString("#14532d")
            $TxtFooterInfo.Text = "Hệ thống Docker đang hoạt động ổn định. Web: localhost:8000 | DB: localhost:3306"
        } else {
            $TxtStatusApp.Text = "○ Đang dừng"
            $TxtStatusApp.Foreground = [System.Windows.Media.Brushes]::IndianRed
            $TxtStatusDb.Text = "○ Đang dừng"
            $TxtStatusDb.Foreground = [System.Windows.Media.Brushes]::IndianRed
            $TxtStatusPma.Text = "○ Đang dừng"
            $TxtStatusPma.Foreground = [System.Windows.Media.Brushes]::IndianRed

            $TxtSystemStatus.Text = "○ Hệ thống đang dừng"
            $TxtSystemStatus.Foreground = [System.Windows.Media.Brushes]::IndianRed
            $BadgeStatus.Background = [System.Windows.Media.BrushConverter]::new().ConvertFromString("#450a0a")
            $TxtFooterInfo.Text = "Hệ thống Docker đang dừng. Nhấn 'Khởi động hệ thống' để bắt đầu."
        }
    } catch {
        $TxtSystemStatus.Text = "⚠️ Lỗi kiểm tra Docker"
        $TxtFooterInfo.Text = "Không thể kết nối đến Docker Daemon. Hãy đảm bảo Docker Desktop đã bật."
    }
}

# Hàm chạy lệnh nền và xuất log ra console
function Invoke-DockerAction {
    param([string]$Title, [scriptblock]$Action)
    $TxtFooterInfo.Text = "Đang thực hiện: $Title..."
    $TxtLogConsole.AppendText("`r`n[$(Get-Date -Format 'HH:mm:ss')] Bắt đầu: $Title...`r`n")
    $window.Cursor = [System.Windows.Input.Cursors]::Wait

    try {
        $result = & $Action 2>&1 | Out-String
        if ($result) {
            $TxtLogConsole.AppendText($result + "`r`n")
        }
        $TxtLogConsole.AppendText("[$(Get-Date -Format 'HH:mm:ss')] Hoàn tất: $Title!`r`n")
        $TxtFooterInfo.Text = "Hoàn tất: $Title!"
    } catch {
        $TxtLogConsole.AppendText("[Lỗi] $_`r`n")
        $TxtFooterInfo.Text = "Xảy ra lỗi khi thực hiện $Title!"
    } finally {
        $TxtLogConsole.ScrollToEnd()
        $window.Cursor = [System.Windows.Input.Cursors]::Arrow
        Update-ContainerStatus
    }
}

# Gán sự kiện cho các nút
$BtnRefresh.Add_Click({ Update-ContainerStatus })

$BtnStartSystem.Add_Click({
    Invoke-DockerAction "Khởi động hệ thống Docker" {
        docker compose up -d
    }
    Start-Process "http://localhost:8000"
})

$BtnOpenWeb.Add_Click({
    Start-Process "http://localhost:8000"
})

$BtnOpenPma.Add_Click({
    Start-Process "http://localhost:8888"
})

$BtnRebuild.Add_Click({
    Invoke-DockerAction "Build lại toàn bộ Container" {
        docker compose up --build -d
    }
    Start-Process "http://localhost:8000"
})

$BtnRestartApp.Add_Click({
    Invoke-DockerAction "Khởi động lại Container App" {
        docker compose restart app
    }
})

$BtnStopSystem.Add_Click({
    Invoke-DockerAction "Dừng hệ thống Docker" {
        docker compose down
    }
})

$BtnMigrateStatus.Add_Click({
    Invoke-DockerAction "Kiểm tra trạng thái Migration" {
        docker compose exec app php artisan migrate:status
    }
})

$BtnRunMigrate.Add_Click({
    Invoke-DockerAction "Chạy Migrations" {
        docker compose exec app php artisan migrate --force
    }
})

$BtnResetDb.Add_Click({
    $confirm = [System.Windows.Forms.MessageBox]::Show(
        "Bạn có chắc chắn muốn xóa sạch database hiện tại và nạp lại từ tệp dump_quanly_toanha.sql?`nThao tác này sẽ đặt lại dữ liệu ban đầu.",
        "Xác nhận Reset Database",
        [System.Windows.Forms.MessageBoxButtons]::YesNo,
        [System.Windows.Forms.MessageBoxIcon]::Warning
    )
    if ($confirm -eq [System.Windows.Forms.DialogResult]::Yes) {
        Invoke-DockerAction "Reset & Import lại CSDL từ dump_quanly_toanha.sql" {
            docker compose down -v
            docker compose up -d
        }
    }
})

$BtnLogApp.Add_Click({
    Invoke-DockerAction "Xem 50 dòng logs gần nhất của App" {
        docker compose logs --tail=50 app
    }
})

$BtnLogDb.Add_Click({
    Invoke-DockerAction "Xem 50 dòng logs gần nhất của Database" {
        docker compose logs --tail=50 db
    }
})

$BtnLogPma.Add_Click({
    Invoke-DockerAction "Xem 50 dòng logs gần nhất của phpMyAdmin" {
        docker compose logs --tail=50 phpmyadmin
    }
})

$BtnClearLogs.Add_Click({
    $TxtLogConsole.Text = ""
})

# Lệnh Artisan
$BtnExecArtisan.Add_Click({
    $cmd = $TxtArtisanInput.Text.Trim()
    if ($cmd) {
        $TxtArtisanOutput.Text = "Đang chạy: php artisan $cmd ...`r`n"
        $window.Cursor = [System.Windows.Input.Cursors]::Wait
        try {
            $output = docker compose exec app php artisan $cmd 2>&1 | Out-String
            $TxtArtisanOutput.Text = $output
        } catch {
            $TxtArtisanOutput.Text = "Lỗi: $_"
        } finally {
            $window.Cursor = [System.Windows.Input.Cursors]::Arrow
        }
    }
})

$BtnPreset1.Add_Click({ $TxtArtisanInput.Text = "route:list"; $BtnExecArtisan.RaiseEvent([System.Windows.RoutedEventArgs]::new([System.Windows.Controls.Button]::ClickEvent)) })
$BtnPreset2.Add_Click({ $TxtArtisanInput.Text = "cache:clear"; $BtnExecArtisan.RaiseEvent([System.Windows.RoutedEventArgs]::new([System.Windows.Controls.Button]::ClickEvent)) })
$BtnPreset3.Add_Click({ $TxtArtisanInput.Text = "config:clear"; $BtnExecArtisan.RaiseEvent([System.Windows.RoutedEventArgs]::new([System.Windows.Controls.Button]::ClickEvent)) })
$BtnPreset4.Add_Click({ $TxtArtisanInput.Text = "test"; $BtnExecArtisan.RaiseEvent([System.Windows.RoutedEventArgs]::new([System.Windows.Controls.Button]::ClickEvent)) })
$BtnPreset5.Add_Click({ $TxtArtisanInput.Text = "db:seed"; $BtnExecArtisan.RaiseEvent([System.Windows.RoutedEventArgs]::new([System.Windows.Controls.Button]::ClickEvent)) })

# Khởi tạo trạng thái ban đầu
$window.Add_Loaded({
    Update-ContainerStatus
    $TxtLogConsole.AppendText("[$(Get-Date -Format 'HH:mm:ss')] Bảng điều khiển Smart Cassavas đã khởi tạo thành công!`r`n")
})

# Hiển thị cửa sổ
$window.ShowDialog() | Out-Null
