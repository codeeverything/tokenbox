Output docs/assets/screens/plugin.mp4
Source demo/tapes/_settings.tape
Set Width 2360
Set Height 1400
Hide
Type `export HOME="$TOKENBOX_DEMO/plugin-home" COMPOSER_HOME="$TOKENBOX_DEMO/plugin-home/.composer" PATH="$TOKENBOX_DEMO_BIN:$PATH" PS1='\[\e[90m\]\w\[\e[0m\] \[\e[95m\]$\[\e[0m\] ' BASH_SILENCE_DEPRECATION_WARNING=1 && cd ~/Code/invoicing && clear`
Enter
Sleep 500ms
Show
Type "composer install"
Sleep 400ms
Enter
Sleep 3s
# Set up new credentials now? [Y/n]
Enter
Sleep 1200ms
Type "token"
Sleep 300ms
Enter
Sleep 800ms
Type@25ms "DEMOfreshINVOICEtoken0000000b7e3"
Sleep 300ms
Enter
Sleep 6s
Screenshot docs/assets/screens/plugin.png
Sleep 2s
