package runr

import "time"

// SetSleep replaces the retry back-off sleep (tests).
func SetSleep(c *Client, f func(time.Duration)) { c.sleep = f }
