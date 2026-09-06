<?php

namespace MiningManager\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * One answer to "may data leave this plugin".
 *
 * The Allow Data Export setting used to decide nothing. Two of the views that
 * read it were served by a controller that never defined the key, and two more
 * by a controller that never passed the flags at all, so every export button
 * fell back to its own default and stayed on. A director could switch the
 * setting off and watch nothing change.
 *
 * Hiding the buttons on its own would not have fixed it either. An export route
 * is a GET with a query string; anyone who had used it once has the URL. The
 * check therefore lives on the actions as well, and this is the single place
 * that decides.
 *
 * Settings export is deliberately not covered. Backing up your own
 * configuration is not the same act as taking mining and tax records out of the
 * plugin, and an admin locked out of their own config backup by a data setting
 * would be a worse surprise than the bug this fixes.
 */
trait GuardsDataExport
{
    /**
     * Whether data may be exported at all.
     *
     * Read through the settings service rather than a controller's own flag
     * list, because a controller's own list is what went wrong here.
     */
    protected function dataExportIsAllowed(): bool
    {
        try {
            $settings = app(\MiningManager\Services\Configuration\SettingsManagerService::class);

            return (bool) ($settings->getFeatureFlags()['allow_export_data'] ?? true);
        } catch (\Throwable $e) {
            // A settings lookup that fails should not take a page down. The
            // default matches the shipped one: exporting is allowed until
            // somebody says otherwise.
            return true;
        }
    }

    /**
     * The response for an export that is switched off.
     *
     * A refusal, not a silent empty file. Somebody who followed an old link
     * should be told the setting is off rather than left wondering why their
     * download had no rows in it.
     */
    protected function refuseDataExport(Request $request)
    {
        $message = trans('mining-manager::settings.data_export_disabled');

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 403);
        }

        return redirect()
            ->back()
            ->with('warning', $message);
    }
}
