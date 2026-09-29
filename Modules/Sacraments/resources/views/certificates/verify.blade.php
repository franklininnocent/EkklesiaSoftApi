<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Certificate verification</title>
  <style>
    body { font-family: Georgia, serif; max-width: 36rem; margin: 3rem auto; padding: 0 1.5rem; color: #1f2937; }
    h1 { font-size: 1.5rem; }
    dl div { display: flex; justify-content: space-between; gap: 1rem; border-bottom: 1px solid #e5e7eb; padding: 0.55rem 0; }
    dt { color: #6b7280; }
  </style>
</head>
<body>
  <h1>Certificate verification</h1>
  <dl>
    <div><dt>Sacrament</dt><dd>{{ $data['sacrament_title'] }}</dd></div>
    <div><dt>Name</dt><dd>{{ $data['recipient_name'] }}</dd></div>
    <div><dt>Date</dt><dd>{{ $data['date_of_event'] }}</dd></div>
    <div><dt>Parish</dt><dd>{{ $data['parish_name'] }}</dd></div>
    <div><dt>Certificate number</dt><dd>{{ $data['certificate_number'] }}</dd></div>
    <div><dt>Status</dt><dd>{{ $data['status'] }}</dd></div>
  </dl>
</body>
</html>
