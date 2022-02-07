#!/bin/bash


#Soundkarten anzeigen WebUI
if [ $1 == "sc_show" ];then
aplay -l >/tmp/soundcards.txt
sed -n '/card/p' /tmp/soundcards.txt >/tmp/soundcards2.txt
fi
