param(
    [Parameter(Mandatory = $true)]
    [string]$To,
    [Parameter(Mandatory = $true)]
    [string]$SubjectBase64,
    [Parameter(Mandatory = $true)]
    [string]$BodyBase64,
    [Parameter(Mandatory = $false)]
    [string]$HtmlBodyBase64 = ""
)

$fromAccount = "sistemas@proserge.com"

try {
    $outlook = New-Object -ComObject Outlook.Application
    $mail = $outlook.CreateItem(0)
    $mail.To = $To

    $subBytes = [Convert]::FromBase64String($SubjectBase64)
    $subject = [System.Text.Encoding]::UTF8.GetString($subBytes)
    $mail.Subject = $subject

    $bodyBytes = [Convert]::FromBase64String($BodyBase64)
    $body = [System.Text.Encoding]::UTF8.GetString($bodyBytes)
    $mail.Body = $body

    if ($HtmlBodyBase64 -and $HtmlBodyBase64.Trim() -ne "") {
        $htmlBodyBytes = [Convert]::FromBase64String($HtmlBodyBase64)
        $htmlBody = [System.Text.Encoding]::UTF8.GetString($htmlBodyBytes)
        $mail.HTMLBody = $htmlBody
    }

    $account = $outlook.Session.Accounts | Where-Object { $_.SmtpAddress -eq $fromAccount }
    if ($account) {
        $mail.SendUsingAccount = $account
    }

    $mail.Send()

    $mail = $null
    $outlook = $null

    exit 0
} catch {
    $msg = $_.Exception.Message -replace "`r`n", " " -replace "`n", " "
    Write-Output "ERROR: $msg"
    exit 1
}
