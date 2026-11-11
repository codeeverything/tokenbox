Output docs/assets/screens/refresh.mp4
Source demo/tapes/_settings.tape
Set Width 2360
Set Height 1340
Source demo/tapes/_env.tape
Type "tokenbox refresh github.acme-corp.com"
Sleep 400ms
Enter
Sleep 2500ms
# "Open that page in your browser?" (yes; demo/bin/open does nothing)
Enter
Sleep 1200ms
Type@25ms "ghp_DEMOenterpriseFRESHx000000000Zt8k"
Sleep 400ms
Enter
Sleep 3s
Screenshot docs/assets/screens/refresh.png
Sleep 2s
