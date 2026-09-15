import http.server
import socketserver
import os

PORT = 8000
DIRECTORY = os.path.join(os.path.dirname(os.path.abspath(__file__)), "public")

class SPAHandler(http.server.SimpleHTTPRequestHandler):
    extensions_map = {
        **http.server.SimpleHTTPRequestHandler.extensions_map,
        ".html": "text/html; charset=utf-8",
        ".js": "application/javascript; charset=utf-8",
        ".mjs": "application/javascript; charset=utf-8",
        ".css": "text/css; charset=utf-8",
        ".json": "application/json; charset=utf-8",
        ".svg": "image/svg+xml; charset=utf-8",
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIRECTORY, **kwargs)

    def end_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        super().end_headers()

    def do_GET(self):
        path = self.translate_path(self.path)
        if not os.path.exists(path) or os.path.isdir(path):
            if not any(self.path.endswith(ext) for ext in [".js", ".mjs", ".css", ".png", ".jpg", ".webp", ".svg", ".ico", ".json", ".woff", ".woff2", ".ttf"]):
                self.path = "/index.html"
        return super().do_GET()

if __name__ == "__main__":
    # Allow port reuse
    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer(("", PORT), SPAHandler) as httpd:
        print(f"Serving SPA with UTF-8 charset on http://127.0.0.1:{PORT}")
        httpd.serve_forever()
