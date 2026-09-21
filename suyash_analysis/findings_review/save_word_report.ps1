$ErrorActionPreference = 'Stop'
$reviewFolder = $PSScriptRoot
$wordApp = New-Object -ComObject Word.Application
$wordApp.Visible = $false
$wordApp.DisplayAlerts = 0
$reportDoc = $null
try {
  $reportDoc = $wordApp.Documents.Open((Join-Path $reviewFolder 'word_report_source.html'), $false, $true)
  $reportDoc.PageSetup.PaperSize = 7
  $reportDoc.PageSetup.TopMargin = $wordApp.CentimetersToPoints(2)
  $reportDoc.PageSetup.BottomMargin = $wordApp.CentimetersToPoints(2)
  $reportDoc.PageSetup.LeftMargin = $wordApp.CentimetersToPoints(1.8)
  $reportDoc.PageSetup.RightMargin = $wordApp.CentimetersToPoints(1.8)
  foreach ($table in $reportDoc.Tables) {
    $table.AutoFitBehavior(2)
    $table.Rows.AllowBreakAcrossPages = 0
    $table.Rows.Item(1).HeadingFormat = -1
  }
  foreach ($shape in $reportDoc.InlineShapes) {
    $shape.LockAspectRatio = -1
    if ($shape.Width -gt 480) { $shape.Width = 480 }
    $shape.AlternativeText = 'Chart captured from the verified sales findings report; accompanying text and data tables state its values and scope.'
  }
  $footerRange = $reportDoc.Sections.Item(1).Footers.Item(1).Range
  $footerRange.ParagraphFormat.Alignment = 2
  $footerRange.Text = 'Review copy | '
  $footerRange.Collapse(0)
  $null = $footerRange.Fields.Add($footerRange,33)
  $reportDoc.SaveAs2((Join-Path $reviewFolder 'Sales_Dashboard_Dataset_Findings_Report.docx'),16)
  $reportDoc.ExportAsFixedFormat((Join-Path $reviewFolder 'Sales_Dashboard_Dataset_Findings_Report_check.pdf'),17)
  Write-Output ('Pages: ' + $reportDoc.ComputeStatistics(2))
  Write-Output ('Tables: ' + $reportDoc.Tables.Count + '; Images: ' + $reportDoc.InlineShapes.Count)
} finally {
  if ($null -ne $reportDoc) { $reportDoc.Close(0) }
  $wordApp.Quit()
  [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($wordApp)
}
