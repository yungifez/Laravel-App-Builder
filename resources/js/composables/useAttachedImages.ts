import { ref } from 'vue';

export type AttachedImage = { file: File; url: string };

/**
 * Pictures an owner attaches to what they ask for (a screenshot, a
 * sketch). They ride along in a form's file input, kept in step with the
 * list shown, and can be added by picking, dropping or pasting.
 */
export function useAttachedImages(max = 4) {
    const input = ref<HTMLInputElement | null>(null);
    const images = ref<AttachedImage[]>([]);
    const dragging = ref(false);

    function sync(): void {
        if (input.value === null) {
            return;
        }

        const files = new DataTransfer();
        images.value.forEach((image) => files.items.add(image.file));
        input.value.files = files.files;
    }

    function add(files: Iterable<File>): void {
        for (const file of files) {
            if (file.type.startsWith('image/') && images.value.length < max) {
                images.value.push({ file, url: URL.createObjectURL(file) });
            }
        }

        sync();
    }

    function remove(index: number): void {
        URL.revokeObjectURL(images.value[index].url);
        images.value.splice(index, 1);
        sync();
    }

    function clear(): void {
        images.value.forEach((image) => URL.revokeObjectURL(image.url));
        images.value = [];
        sync();
    }

    function drop(event: DragEvent): void {
        dragging.value = false;
        add(Array.from(event.dataTransfer?.files ?? []));
    }

    // A pasted screenshot is attached, as in any chat; pasted text is not
    // touched.
    function paste(event: ClipboardEvent): void {
        const files = Array.from(event.clipboardData?.files ?? []).filter(
            (file) => file.type.startsWith('image/'),
        );

        if (files.length > 0) {
            event.preventDefault();
            add(files);
        }
    }

    return { input, images, dragging, max, add, remove, clear, drop, paste };
}
