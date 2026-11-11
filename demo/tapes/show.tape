Source demo/tapes/_settings.tape
Set Width 2400
Set Height 1640
Source demo/tapes/_env.tape
Type "tokenbox show github.com"
Sleep 300ms
Enter
Wait+Screen@20s /Change with/
Sleep 500ms
Screenshot docs/assets/screens/show.png
Sleep 1s
