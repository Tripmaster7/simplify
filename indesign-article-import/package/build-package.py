"""
Builds the standalone package of the InDesign script for people outside this repository:

    ArtikelImport-InDesign-v<version>.zip
        ArtikelImport-InDesign/
            ArtikelImport.jsx
            Anleitung.html, Anleitung.pdf     German guide
            Guide.html, Guide.pdf             English guide
            Beispiel/                         example article and pictures
                Beispielartikel.docx
                Aufmacherbild.jpg, Bild 1.jpg, Bild 2.jpg, Autorenbild.jpg

Run: python indesign-article-import/package/build-package.py [output folder]
The default output folder is the repository root. Needs Pillow for the example pictures and
Microsoft Edge or Google Chrome for the PDFs (without a browser, the PDFs are left out).
"""

import importlib.util
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT = os.path.dirname(HERE)
REPO = os.path.dirname(PROJECT)
SCRIPT = os.path.join(PROJECT, 'ArtikelImport.jsx')
TOP = 'ArtikelImport-InDesign'

sys.dont_write_bytecode = True  # no __pycache__ in tests/
spec = importlib.util.spec_from_file_location('make_test_docx', os.path.join(PROJECT, 'tests', 'make-test-docx.py'))
docx = importlib.util.module_from_spec(spec)
spec.loader.exec_module(docx)
para, run, link = docx.para, docx.run, docx.link

EXAMPLE = [
    para('[TITLE: Drei Tage am Fluss]'),
    para('[SUBTITLE: Mit dem Kanu durch eine stille Landschaft]'),
    para('[AUTHOR_NAME: Anna Beispiel]'),
    para('[REISEZEIT: Juni 2025]'),
    para('Morgens liegt Nebel über dem Wasser, und außer dem Eintauchen der Paddel ist nichts zu hören. '
         'Drei Tage lang folgen wir dem Fluss bis zur Mündung.'),
    para('Der Einstieg liegt hinter einer alten Mühle. Das Boot ist schnell gepackt: Zelt, Kocher, ',
         run('wasserdichte Säcke', bold=True), ' und eine Karte, die ', run('eigentlich', italic=True),
         ' nicht nötig ist. Alles Wichtige steht auf ', link('der Seite des Verleihs', 'rIdLink'), '.'),
    para('[Bild 1: Der Einstieg bei der alten Mühle]'),
    para('[HEADER Der erste Tag]'),
    para('Die Strömung ist schwach, wir kommen trotzdem gut voran. Am Nachmittag suchen wir einen Lagerplatz.'),
    para('Was in kein Gepäck fehlen sollte:'),
    para('Stirnlampe', style='ListParagraph', num=(1, 0)),
    para('Wasserfilter', style='ListParagraph', num=(1, 0)),
    para('Ersatzpaddel', style='ListParagraph', num=(1, 0)),
    para('am besten geteilt', style='ListParagraph', num=(1, 1)),
    para('[HEADER 3 Abends am Feuer]'),
    para('Ein Reiher steht reglos im Schilf [Bild 2: Ein Graureiher im Schilf] und fliegt erst auf, als wir vorbeitreiben.'),
    para('[HEADER Ankunft]'),
    para('Am dritten Tag weitet sich der Fluss. Die Mündung ist breiter, als wir dachten, und der Wind ',
         run('frischt auf', bold=True, italic=True), '.'),
    para('[BIO: Anna Beispiel ist eine erfundene Autorin für diesen Beispielartikel.'),
    para('Sie paddelt am liebsten auf kleinen Flüssen.]'),
]

PICTURES = [
    ('Aufmacherbild.jpg', 'Aufmacherbild', (1800, 1200), (46, 94, 120)),
    ('Bild 1.jpg', 'Bild 1', (1500, 1000), (92, 120, 70)),
    ('Bild 2.jpg', 'Bild 2', (1000, 1400), (130, 100, 60)),
    ('Autorenbild.jpg', 'Autorenbild', (800, 800), (110, 80, 120)),
]


def script_version():
    with open(SCRIPT, encoding='ascii') as f:
        match = re.search(r"A\.VERSION = '([^']+)'", f.read())
    return match.group(1)


def picture(path, label, size, colour):
    image = Image.new('RGB', size, colour)
    draw = ImageDraw.Draw(image)
    light = tuple(min(255, c + 70) for c in colour)
    for y in range(0, size[1], 40):
        draw.line([(0, y), (size[0], y)], fill=tuple((a + b) // 2 for a, b in zip(colour, light)), width=2)
    font = ImageFont.load_default(size=size[0] // 10)
    box = draw.textbbox((0, 0), label, font=font)
    draw.text(((size[0] - box[2]) / 2, (size[1] - box[3]) / 2), label, fill=(255, 255, 255), font=font)
    image.save(path, quality=85)


def browser():
    for path in [
        r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        r'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        r'C:\Program Files\Google\Chrome\Application\chrome.exe',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ]:
        if os.path.exists(path):
            return path
    return shutil.which('msedge') or shutil.which('google-chrome') or shutil.which('chromium')


def pdf(exe, html, target):
    with tempfile.TemporaryDirectory() as profile:
        subprocess.run([exe, '--headless', '--disable-gpu', '--no-pdf-header-footer', '--user-data-dir=' + profile,
                        '--print-to-pdf=' + target, 'file:///' + html.replace('\\', '/')],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
    if not os.path.exists(target):
        raise RuntimeError('No PDF written for ' + html)


def main():
    out_dir = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else REPO
    version = script_version()
    target = os.path.join(out_dir, 'ArtikelImport-InDesign-v' + version + '.zip')

    with tempfile.TemporaryDirectory() as work:
        root = os.path.join(work, TOP)
        example = os.path.join(root, 'Beispiel')
        os.makedirs(example)
        shutil.copy(SCRIPT, root)
        docx.write_docx(os.path.join(example, 'Beispielartikel.docx'), EXAMPLE, {'rIdLink': 'https://example.com/'})
        for name, label, size, colour in PICTURES:
            picture(os.path.join(example, name), label, size, colour)

        exe = browser()
        for name in ['Anleitung', 'Guide']:
            html = os.path.join(root, name + '.html')
            shutil.copy(os.path.join(HERE, name + '.html'), html)
            if exe:
                pdf(exe, html, os.path.join(root, name + '.pdf'))
            else:
                print('No Edge or Chrome found: ' + name + '.pdf left out.')

        with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as package:
            for folder, _, files in os.walk(root):
                for name in sorted(files):
                    path = os.path.join(folder, name)
                    package.write(path, os.path.relpath(path, work).replace(os.sep, '/'))

    print('Wrote ' + target)


if __name__ == '__main__':
    main()
