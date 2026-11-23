"""
Writes tests/fixtures/Testartikel.docx: a small article that uses every rule of the import format
(markers, headings, bold/italic, a link, lists, picture markers, a multi-paragraph bio, an emoji).

Run: python indesign-article-import/tests/make-test-docx.py
"""

import os
import zipfile
from xml.sax.saxutils import escape

HERE = os.path.dirname(os.path.abspath(__file__))
TARGET = os.path.join(HERE, 'fixtures', 'Testartikel.docx')

W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'


def run(text, bold=False, italic=False):
    props = ('<w:b/>' if bold else '') + ('<w:i/>' if italic else '')
    props = '<w:rPr>' + props + '</w:rPr>' if props else ''
    return '<w:r>' + props + '<w:t xml:space="preserve">' + escape(text) + '</w:t></w:r>'


def link(text, rel_id):
    return '<w:hyperlink r:id="' + rel_id + '">' + '<w:r><w:rPr><w:rStyle w:val="Hyperlink"/></w:rPr><w:t xml:space="preserve">' + escape(text) + '</w:t></w:r></w:hyperlink>'


def para(*runs, style=None, num=None):
    props = ''
    if style:
        props += '<w:pStyle w:val="' + style + '"/>'
    if num:
        props += '<w:numPr><w:ilvl w:val="' + str(num[1]) + '"/><w:numId w:val="' + str(num[0]) + '"/></w:numPr>'
    props = '<w:pPr>' + props + '</w:pPr>' if props else ''
    body = ''.join(r if r.startswith('<') else run(r) for r in runs)
    return '<w:p>' + props + body + '</w:p>'


paragraphs = [
    para('[TITLE: Testartikel für den Import]'),
    para('[SUBTITLE: Alle Regeln auf einmal]'),
    para('[AUTHOR_NAME: Anna Autorin]'),
    para('[REISEZEIT: Mai bis August 2024]'),
    para('[WRITING_DATE: 2026-11-22]'),
    para('Der Vorspann steht immer am Anfang und ist ', run('fett', bold=True), ' markiert.'),
    para('Normal ', run('fett', bold=True), ' und ', run('kursiv', italic=True), ' und ', run('beides', bold=True, italic=True),
         ', dazu ', link('ein Link', 'rIdLink'), ' zum Schluss.'),
    para('Ein Emoji \U0001F600 vor ', run('fettem Text', bold=True), ' prüft die Zählung.'),
    para('[Bild 1: Erste Bildunterschrift]'),
    para('[HEADER Abschnitt zwei]'),
    para('Eins', style='ListParagraph', num=(1, 0)),
    para('Zwei', style='ListParagraph', num=(1, 0)),
    para('Unterpunkt', style='ListParagraph', num=(1, 1)),
    para('Drei', style='ListParagraph', num=(1, 0)),
    para('[RESTRICT]'),
    para('[HEADER 3 Ebene drei]'),
    para('Text mit Bild [Bild 2: Zweite Bildunterschrift] mitten im Absatz.'),
    para('[HEADER Warnung}'),
    para('[BIO: Anna Autorin reist seit vielen Jahren.'),
    para('Zweiter Absatz der Bio.]'),
]

document = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<w:document xmlns:w="' + W + '" xmlns:r="' + R + '"><w:body>'
    + ''.join(paragraphs)
    + '<w:sectPr/></w:body></w:document>'
)

styles = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<w:styles xmlns:w="' + W + '">'
    '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
    '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/></w:style>'
    '<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>'
    '</w:styles>'
)

numbering = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<w:numbering xmlns:w="' + W + '">'
    '<w:abstractNum w:abstractNumId="0">'
    '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
    '<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="-"/><w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr></w:lvl>'
    '</w:abstractNum>'
    '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
    '</w:numbering>'
)

content_types = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
    '</Types>'
)

package_rels = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    '</Relationships>'
)

document_rels = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
    '<Relationship Id="rIdLink" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com/" TargetMode="External"/>'
    '</Relationships>'
)

os.makedirs(os.path.dirname(TARGET), exist_ok=True)
with zipfile.ZipFile(TARGET, 'w', zipfile.ZIP_DEFLATED) as docx:
    docx.writestr('[Content_Types].xml', content_types)
    docx.writestr('_rels/.rels', package_rels)
    docx.writestr('word/document.xml', document)
    docx.writestr('word/_rels/document.xml.rels', document_rels)
    docx.writestr('word/styles.xml', styles)
    docx.writestr('word/numbering.xml', numbering)

print('Wrote ' + TARGET)
