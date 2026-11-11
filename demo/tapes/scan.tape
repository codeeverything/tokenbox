Source demo/tapes/_settings.tape
Set Width 2100
Set Height 1400
Source demo/tapes/_env.tape
Type "tokenbox scan --all"
Sleep 300ms
Enter
Wait+Screen@20s /projects found/
Sleep 500ms
Screenshot docs/assets/screens/scan.png
Sleep 1s
