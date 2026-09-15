import os
import re

MOJIBAKE_PATTERNS = [
    re.compile(r'Ã[¡-ÿ]'),
    re.compile(r'á»[¡-ÿ]'),
    re.compile(r'Ä‘'),
    re.compile(r'\ufffd'),
    re.compile(r'[À-ỹ]\?[À-ỹ]'),
]

root_dirs = [
    r'c:\Users\Asus\Downloads\QUANLY_TOANHA-DANCU\resources\js',
    r'c:\Users\Asus\Downloads\QUANLY_TOANHA-DANCU\resources\views',
]

found = []
for rdir in root_dirs:
    for root, dirs, files in os.walk(rdir):
        for f in files:
            if f.endswith(('.tsx', '.ts', '.jsx', '.js', '.php', '.html', '.blade.php')):
                path = os.path.join(root, f)
                try:
                    with open(path, 'r', encoding='utf-8') as fp:
                        content = fp.read()
                        for i, line in enumerate(content.splitlines(), 1):
                            for pat in MOJIBAKE_PATTERNS:
                                if pat.search(line):
                                    found.append((path, i, line.strip()))
                                    break
                except Exception as e:
                    print(f"Error reading {path}: {e}")

print(f"Total potential mojibake lines found: {len(found)}")
for p, l, text in found[:30]:
    print(f"{os.path.basename(p)}:{l} -> {text}")
