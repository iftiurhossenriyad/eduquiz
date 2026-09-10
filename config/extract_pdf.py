import sys
from pypdf import PdfReader

reader = PdfReader(sys.argv[1])
text = "\n".join((page.extract_text() or "") for page in reader.pages)
print(text)
