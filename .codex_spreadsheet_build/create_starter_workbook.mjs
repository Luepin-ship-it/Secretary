import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const outputDir = path.resolve("..", "outputs", "starter_workbook");
await fs.mkdir(outputDir, { recursive: true });

const workbook = Workbook.create();
const summary = workbook.worksheets.add("Summary");
const tasks = workbook.worksheets.add("Tasks");
const budget = workbook.worksheets.add("Budget");
const notes = workbook.worksheets.add("Notes");

for (const sheet of [summary, tasks, budget, notes]) {
  sheet.showGridLines = false;
}

summary.getRange("A1:F1").values = [["Starter Workbook", "", "", "", "", ""]];
summary.getRange("A2:F2").values = [["A simple workbook for tasks, budget, and notes.", "", "", "", "", ""]];
summary.getRange("A4:B8").values = [
  ["Metric", "Value"],
  ["Total tasks", null],
  ["Completed tasks", null],
  ["Budget planned", null],
  ["Budget actual", null],
];
summary.getRange("B5").formulas = [["=COUNTA(Tasks!A2:A101)"]];
summary.getRange("B6").formulas = [["=COUNTIF(Tasks!E2:E101,\"Done\")"]];
summary.getRange("B7").formulas = [["=SUM(Budget!B2:B101)"]];
summary.getRange("B8").formulas = [["=SUM(Budget!C2:C101)"]];
summary.getRange("A10:D14").values = [
  ["Status", "Count", "Share", ""],
  ["Not Started", null, null, ""],
  ["In Progress", null, null, ""],
  ["Blocked", null, null, ""],
  ["Done", null, null, ""],
];
summary.getRange("B11").formulas = [["=COUNTIF(Tasks!E2:E101,A11)"]];
summary.getRange("B11:B14").fillDown();
summary.getRange("C11").formulas = [["=IFERROR(B11/SUM($B$11:$B$14),0)"]];
summary.getRange("C11:C14").fillDown();
summary.getRange("A16:D21").values = [
  ["Budget Category", "Planned", "Actual", "Variance"],
  ["Operations", null, null, null],
  ["Marketing", null, null, null],
  ["Tools", null, null, null],
  ["Other", null, null, null],
  ["Total", null, null, null],
];
summary.getRange("B17").formulas = [["=SUMIF(Budget!A2:A101,A17,Budget!B2:B101)"]];
summary.getRange("B17:D20").fillDown();
summary.getRange("C17").formulas = [["=SUMIF(Budget!A2:A101,A17,Budget!C2:C101)"]];
summary.getRange("C17:C20").fillDown();
summary.getRange("D17").formulas = [["=B17-C17"]];
summary.getRange("D17:D20").fillDown();
summary.getRange("B21").formulas = [["=SUM(B17:B20)"]];
summary.getRange("C21").formulas = [["=SUM(C17:C20)"]];
summary.getRange("D21").formulas = [["=B21-C21"]];

tasks.getRange("A1:F1").values = [["Task ID", "Task", "Owner", "Due Date", "Status", "Notes"]];
tasks.getRange("A2:F6").values = [
  ["T-001", "Define project scope", "Owner", new Date("2026-06-03"), "Done", "Example row"],
  ["T-002", "Collect requirements", "Owner", new Date("2026-06-07"), "In Progress", ""],
  ["T-003", "Draft delivery plan", "Owner", new Date("2026-06-12"), "Not Started", ""],
  ["T-004", "Review risks", "Owner", new Date("2026-06-14"), "Blocked", "Needs decision"],
  ["T-005", "Finalize handoff", "Owner", new Date("2026-06-17"), "Not Started", ""],
];

budget.getRange("A1:D1").values = [["Category", "Planned", "Actual", "Variance"]];
budget.getRange("A2:D5").values = [
  ["Operations", 5000, 3600, null],
  ["Marketing", 3000, 1800, null],
  ["Tools", 1500, 1200, null],
  ["Other", 1000, 600, null],
];
budget.getRange("D2").formulas = [["=B2-C2"]];
budget.getRange("D2:D101").fillDown();

notes.getRange("A1:D1").values = [["Date", "Topic", "Note", "Owner"]];
notes.getRange("A2:D2").values = [[new Date("2026-06-01"), "Kickoff", "Use this sheet for important decisions and context.", "Owner"]];

const headerRanges = ["Summary!A4:B4", "Summary!A10:C10", "Summary!A16:D16", "Tasks!A1:F1", "Budget!A1:D1", "Notes!A1:D1"];
for (const address of headerRanges) {
  const range = workbook.getRange ? workbook.getRange(address) : null;
  if (range) {
    range.format = { fill: "#1F2937", font: { bold: true, color: "#FFFFFF" } };
  }
}

summary.getRange("A1:F1").format = { fill: "#0F766E", font: { bold: true, color: "#FFFFFF", size: 16 } };
summary.getRange("A2:F2").format = { fill: "#E5E7EB", font: { color: "#374151" } };
tasks.getRange("D2:D101").format.numberFormat = "yyyy-mm-dd";
notes.getRange("A2:A101").format.numberFormat = "yyyy-mm-dd";
summary.getRange("B7:B8").format.numberFormat = "$#,##0";
summary.getRange("C11:C14").format.numberFormat = "0.0%";
summary.getRange("B17:D21").format.numberFormat = "$#,##0";
budget.getRange("B2:D101").format.numberFormat = "$#,##0";

for (const sheet of [summary, tasks, budget, notes]) {
  sheet.getUsedRange().format.autofitColumns();
  sheet.freezePanes.freezeRows(1);
}

const check = await workbook.inspect({
  kind: "table",
  range: "Summary!A1:D21",
  include: "values,formulas",
  tableMaxRows: 21,
  tableMaxCols: 4,
});
console.log(check.ndjson);

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A",
  options: { useRegex: true, maxResults: 100 },
  summary: "final formula error scan",
});
console.log(errors.ndjson);

const outPath = path.join(outputDir, "starter_workbook.xlsx");
const xlsx = await SpreadsheetFile.exportXlsx(workbook);
await xlsx.save(outPath);

const savedWorkbook = await SpreadsheetFile.importXlsx(await FileBlob.load(outPath));
for (const sheetName of ["Summary", "Tasks", "Budget", "Notes"]) {
  const preview = await savedWorkbook.render({ sheetName, autoCrop: "all", scale: 1, format: "png" });
  await fs.writeFile(path.join(outputDir, `${sheetName.toLowerCase()}.png`), new Uint8Array(await preview.arrayBuffer()));
}

console.log(outPath);
