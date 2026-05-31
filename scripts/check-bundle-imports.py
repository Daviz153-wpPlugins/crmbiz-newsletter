#!/usr/bin/env python3
"""
Vue 빌드 번들 무결성 체크.
각 엔트리 파일(dashboard.js, history.js)이 app.js에서 import하는 이름이
app.js의 export 목록에 모두 존재하는지 확인합니다.

사용: python3 scripts/check-bundle-imports.py [assets/vue 경로]
"""
import re, sys, pathlib

def parse_imports(code, source_file):
    names = set()
    for imp in re.findall(r'import\{([^}]+)\}from"\./app\.js"', code):
        for part in imp.split(','):
            p = part.strip()
            names.add(p.split(' as ')[0].strip() if ' as ' in p else p)
    return names

def parse_exports(code):
    names = set()
    for exp in re.findall(r'export\{([^}]+)\}', code):
        for part in exp.split(','):
            p = part.strip()
            names.add(p.split(' as ')[1].strip() if ' as ' in p else p)
    return names

def check(entry_path, app_path):
    entry_code = pathlib.Path(entry_path).read_text()
    app_code   = pathlib.Path(app_path).read_text()

    imp_names = parse_imports(entry_code, entry_path)
    exp_names = parse_exports(app_code)

    if not imp_names:
        print(f"⚠️  {entry_path}: app.js import 없음 (self-contained 번들)")
        return True

    missing = imp_names - exp_names
    if missing:
        print(f"❌ {entry_path}: app.js 누락 export → {sorted(missing)}")
        return False

    print(f"✅ {entry_path}: {len(imp_names)}개 import 모두 확인")
    return True

def main():
    base = sys.argv[1] if len(sys.argv) > 1 else 'assets/vue'
    app  = f'{base}/app.js'

    if not pathlib.Path(app).exists():
        print(f"❌ {app} 파일 없음")
        sys.exit(1)

    entries = ['dashboard', 'history']
    results = [check(f'{base}/{e}.js', app) for e in entries]

    if all(results):
        print("\n✅ 번들 무결성 검사 통과")
        sys.exit(0)
    else:
        print("\n❌ 번들 무결성 검사 실패 — npm run build 후 assets/vue/* 전체를 커밋하세요")
        sys.exit(1)

if __name__ == '__main__':
    main()
