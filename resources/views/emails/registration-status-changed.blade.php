<!doctype html>
<html lang="en">
<body>
    <p>Your BINAA2 company registration request has been updated.</p>
    <p><strong>Request:</strong> {{ $requestNumber }}</p>
    <p><strong>Company:</strong> {{ $companyName }}</p>
    <p><strong>Status:</strong> {{ $fromStatus }} → {{ $toStatus }}</p>
    <p><strong>Action:</strong> {{ $action }}</p>
    @if(trim($note) !== '')
        <p><strong>Reviewer note:</strong></p>
        <p>{{ $note }}</p>
    @endif
    <p>You can follow the request status from the registration portal.</p>
</body>
</html>
