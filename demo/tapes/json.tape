Source demo/tapes/_settings.tape
Set Width 2000
Set Height 700
Source demo/tapes/_env.tape
Type `tokenbox status --json | jq -c '.credentials[] | [.host, .health.state, .health.days_left]'`
Sleep 300ms
Enter
Wait+Screen@20s /satis/
Sleep 1200ms
Type `tokenbox status --json > report.json || echo "exit code $?, something needs fixing"`
Sleep 300ms
Enter
Wait+Screen@20s /exit code 1/
Sleep 500ms
Screenshot docs/assets/screens/json.png
Sleep 1s
