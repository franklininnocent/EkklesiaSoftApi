EkklesiaSoft

Security Alert — Password Recovery Limit Reached

Multiple password recovery attempts were detected for:

User: {{ $targetUserName }} ({{ $targetUserEmail }})
@if($tenantName)
Church: {{ $tenantName }}
@endif
Recovery attempts today: {{ $attemptCount }}
Detected at: {{ $detectedAt }}

If this activity was not expected, please investigate.
