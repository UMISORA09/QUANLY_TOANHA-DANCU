# Agent Reach cho Codex / coding agent

Agent Reach là lớp chọn công cụ và chẩn đoán cho agent, chạy trên máy phát triển.
Không có service Agent Reach trong Laravel, React, Docker hoặc CI/CD.

Nguồn chính thức:
- [Hướng dẫn cài](https://github.com/Panniantong/Agent-Reach/blob/main/docs/install.md)
- [Tham chiếu công cụ](https://github.com/Panniantong/Agent-Reach/blob/main/agent_reach/skill/SKILL_en.md)
- Phiên bản đã kiểm tra: 1.5.0, commit `a19a171fa980a0785849596492e0af4db800c82f`.
  Commit cố định giúp tái lập; đọc hướng dẫn update trước khi đổi phiên bản.

## Cài riêng trên Windows

Chạy trên host, ngoài thư mục dự án. Python >= 3.10 là đủ; không cần kích hoạt
Activate.ps1 hoặc đổi ExecutionPolicy.

```powershell
py -3 -m venv "$env:USERPROFILE\.agent-reach-venv"
$python = Join-Path $env:USERPROFILE '.agent-reach-venv\Scripts\python.exe'
$ar = Join-Path $env:USERPROFILE '.agent-reach-venv\Scripts\agent-reach.exe'
& $python -m pip install 'https://github.com/Panniantong/agent-reach/archive/a19a171fa980a0785849596492e0af4db800c82f.zip'
$env:PATH = (Split-Path $ar) + ';' + $env:PATH
& $ar install --env=auto --dry-run
& $ar install --env=auto --safe
& $ar doctor
& $ar doctor --json
```

PATH chỉ đổi trong phiên terminal này. Cài từ GitHub chính thức, không từ gói
PyPI trùng tên. Venv chỉ chứa công cụ Python của agent.

Linux/macOS: dùng venv `~/.agent-reach-venv`, cài cùng URL commit với pip
của venv rồi chạy cùng các lệnh kiểm tra. Không dùng sudo.

`--dry-run` xem trước; `--safe` chỉ kiểm tra và không cài dependency hệ thống
hay ghi cấu hình. Nếu thiếu Node, gh hoặc mcporter, báo rõ thay vì tự chạy
`--system`. Chỉ sau khi chủ máy đồng ý thay đổi hệ thống mới dùng:

```text
agent-reach install --env=auto --system
```

Không dùng `--channels=all`, không chạy wizard `setup`, không nhập hoặc trích
cookie, không bật OpenCLI hoặc kênh cần đăng nhập khi chưa có đồng ý riêng.

## GitHub, Web và Exa

**GitHub:** tận dụng connector GitHub của Codex nếu đã kết nối. CLI là luồng
xác thực riêng, kiểm tra nó độc lập:

```powershell
gh auth status
gh repo view UMISORA09/QUANLY_TOANHA-DANCU --json nameWithOwner,defaultBranchRef,url
gh api repos/UMISORA09/QUANLY_TOANHA-DANCU/contents/AGENTS.md --jq .sha
```

Nếu CLI hết phiên đăng nhập, người dùng chạy `gh auth login --hostname github.com`
và xác thực trong trình duyệt. Không đưa token vào repo hay nội dung chat.
Connector hoạt động không có nghĩa là doctor của CLI sẽ báo GitHub OK.

**Web:** Jina Reader, chỉ dùng URL công khai:

```powershell
curl.exe --fail --location --max-time 30 'https://r.jina.ai/https://laravel.com/docs/13.x'
```

**Exa cho Codex:** dùng MCP HTTP trực tiếp, không cần thêm package vào ứng dụng.
Kiểm tra cấu hình trước; nếu chưa có endpoint này, đăng ký trong cấu hình
cá nhân của Codex:

```powershell
codex mcp list
codex mcp add agent-reach-exa --url https://mcp.exa.ai/mcp
```

CLI có thể tự mở luồng OAuth khi thêm server. Nếu có yêu cầu OAuth, người dùng
phải tự xác thực; server được ghi vào cấu hình chưa có nghĩa là đã đăng nhập.
Có thể tiếp tục bằng `codex mcp login agent-reach-exa`.

Mở chat Codex mới sau khi đăng ký/xác thực và gọi `web_search_exa` với
`query="Laravel 13 documentation"`, `numResults=3`. Kiểm tra kết quả thật;
tên server xuất hiện trong danh sách chưa chứng minh tìm kiếm hoạt động.

Agent Reach doctor kiểm tra Exa qua **mcporter**, không qua cấu hình Codex.
Vì vậy Exa MCP trực tiếp có thể hoạt động trong Codex trong khi doctor vẫn
báo thiếu mcporter. Nếu mcporter đã được cài/cấu hình với sự đồng ý của chủ máy:

```text
mcporter config add exa https://mcp.exa.ai/mcp --scope home
mcporter call exa.web_search_exa query="Laravel 13 documentation" numResults=3
```

Không cần bật cả hai đường Exa. Không ghi cấu hình hoặc token vào
`.agents/mcp_config.json` của bản local; cấu hình Playwright đang có phải được giữ.

## Dùng với dự án

Mở repo trong Codex và dùng branch làm việc của bạn. Codex đọc `AGENTS.md`
để biết giới hạn và cách dùng công cụ. Có thể yêu cầu:

> Dùng Agent Reach kiểm tra GitHub và tìm tài liệu Laravel đúng phiên bản trong
> composer.lock. Đọc luồng tính năng trước khi sửa, rồi chạy kiểm thử thích hợp.
> Không gửi dữ liệu cư dân hoặc mã riêng tư ra ngoài.

Agent Reach không cung cấp command bọc mọi thao tác đọc/tìm kiếm: agent gọi
`gh`, Jina hoặc Exa trực tiếp. Hướng dẫn Laravel Boost hiện có vẫn áp dụng.

Chỉ kiểm tra công cụ host; không chạy migration, deploy, thay Docker Compose,
workflow, composer.json/package.json hoặc lockfile để cài Agent Reach.
Lỗi doctor về kênh chưa được cho phép là trạng thái chưa cấu hình; không tự sửa
bằng đăng nhập/cookie. Lưu kết quả doctor và kết quả gọi thực tế khi báo cáo.

## Gỡ cấu hình riêng

```powershell
codex mcp remove agent-reach-exa
& "$env:USERPROFILE\.agent-reach-venv\Scripts\python.exe" -m pip uninstall agent-reach
```

Chỉ gỡ server đã thêm cho tích hợp này. Không chạy `agent-reach uninstall`
mù quáng vì có thể ảnh hưởng cấu hình/skill dùng chung của agent khác.
