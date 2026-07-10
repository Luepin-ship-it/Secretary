import { FileBlob, SpreadsheetFile } from '@oai/artifact-tool';
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load('../outputs/starter_workbook/starter_workbook.xlsx'));
const sheets = await workbook.inspect({ kind: 'sheet', include: 'name' });
console.log(sheets.ndjson);
const errors = await workbook.inspect({ kind: 'match', searchTerm: '#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A', options: { useRegex: true, maxResults: 100 } });
console.log(errors.ndjson);
